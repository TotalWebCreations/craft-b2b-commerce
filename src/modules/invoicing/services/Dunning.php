<?php

namespace totalwebcreations\b2bcommerce\modules\invoicing\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Db;
use DateTimeImmutable;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\AgingBucket;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;

/**
 * Computes and sends overdue-invoice payment reminders (dunning). For every outstanding invoice
 * order and every configured day-offset the order is now past, a reminder is owed unless one has
 * already been logged for that exact (order, offset). Sending is lenient: a failure is logged and
 * NOT recorded as sent, so a later run retries; nothing here ever throws. The unique (orderId,
 * offset) index on b2b_dunning_log is the hard de-dup backstop behind {@see hasReminderBeenSent()}.
 */
class Dunning extends Component
{
    /**
     * @return array<int, array{order: Order, offset: int, daysPastDue: int, balance: float}>
     */
    public function dueReminders(Company $company, ?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable('now');
        $offsets = Plugin::getInstance()->getSettings()->dunningOffsets;

        if ($offsets === []) {
            return [];
        }

        $due = [];

        foreach (Plugin::getInstance()->creditBalance->getOutstandingOrders($company->id) as $order) {
            $daysPastDue = AgingBucket::daysPastDue($order->b2bPaymentDueDate, $asOf);

            if ($daysPastDue <= 0) {
                continue;
            }

            foreach ($offsets as $offset) {
                if ($daysPastDue < $offset) {
                    continue;
                }

                if ($this->hasReminderBeenSent((int) $order->id, $offset)) {
                    continue;
                }

                $due[] = [
                    'order' => $order,
                    'offset' => $offset,
                    'daysPastDue' => $daysPastDue,
                    'balance' => $order->getOutstandingBalance(),
                ];
            }
        }

        return $due;
    }

    public function hasReminderBeenSent(int $orderId, int $offset): bool
    {
        return (new Query())
            ->from('{{%b2b_dunning_log}}')
            ->where(['orderId' => $orderId, 'offset' => $offset])
            ->exists();
    }

    /**
     * Emails the company's administrators a payment reminder for one overdue invoice at one offset,
     * then logs the send so it never fires again for that (order, offset). Returns false and logs a
     * warning — without writing the log row, so a later run can retry — when the reminder was already
     * sent, when the company has no administrator to notify, or when the mail send fails.
     */
    public function sendReminder(Company $company, Order $order, int $offset, int $daysPastDue): bool
    {
        if ($this->hasReminderBeenSent((int) $order->id, $offset)) {
            return false;
        }

        $recipients = $this->administratorEmails($company);

        if ($recipients === []) {
            Craft::warning("No administrator to send a payment reminder to for company {$company->id}", 'b2b-commerce');

            return false;
        }

        $dueDate = $order->b2bPaymentDueDate;

        try {
            $sent = Craft::$app->getMailer()
                ->composeFromKey('b2b_payment_reminder', [
                    'company' => $company,
                    'order' => $order,
                    'reference' => $order->reference ?: $order->getShortNumber(),
                    'dueDate' => $dueDate?->format('Y-m-d') ?? '',
                    'daysOverdue' => $daysPastDue,
                    'amountDue' => Craft::$app->getFormatter()->asCurrency($order->getOutstandingBalance(), $order->currency),
                ])
                ->setTo($recipients)
                ->send();
        } catch (\Throwable $e) {
            // Genuinely never fatal: a mailer/transport exception must not abort the dunning run for
            // every other order. Treat it exactly like a failed send -- logged, not recorded, retried
            // next run.
            Craft::warning("Failed to send payment reminder for order {$order->id} at offset {$offset}: {$e->getMessage()}", 'b2b-commerce');

            return false;
        }

        if (!$sent) {
            Craft::warning("Failed to send payment reminder for order {$order->id} at offset {$offset}", 'b2b-commerce');

            return false;
        }

        try {
            Db::insert('{{%b2b_dunning_log}}', [
                'orderId' => (int) $order->id,
                'offset' => $offset,
                'dateSent' => Db::prepareDateForDb(new DateTimeImmutable('now')),
            ]);
        } catch (\Throwable $e) {
            // The unique (orderId, offset) index is the hard de-dup backstop: if a concurrent run
            // already logged this exact reminder between our check and this insert, the constraint
            // rejects the duplicate row. The email above may have gone out twice in that narrow race,
            // but the log is not corrupted and nothing here is allowed to throw, so swallow it.
            Craft::warning("Payment reminder log insert failed for order {$order->id} at offset {$offset} (likely a concurrent run): {$e->getMessage()}", 'b2b-commerce');
        }

        return true;
    }

    /** @return array<int, string> */
    private function administratorEmails(Company $company): array
    {
        $emails = [];

        foreach (Plugin::getInstance()->companyMembers->getMemberUsers($company->id) as $row) {
            if ($row['role'] !== CompanyRole::Admin) {
                continue;
            }

            if ($row['user']->email) {
                $emails[] = $row['user']->email;
            }
        }

        return array_values(array_unique($emails));
    }
}
