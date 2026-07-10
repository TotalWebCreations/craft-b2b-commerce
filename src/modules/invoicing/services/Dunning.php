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
use yii\db\IntegrityException;

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
     * sent, when the company has no administrator to notify, when the mail send fails, or when the log
     * insert itself fails for any reason other than the benign duplicate-key race with a concurrent run.
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
        } catch (IntegrityException $e) {
            // An IntegrityException here is NOT trusted at face value: it could be the benign
            // concurrent-duplicate race on the unique (orderId, offset) index, or it could be a real
            // failure such as a foreign-key violation (e.g. the order was deleted between the send
            // above and this insert). The only reliable way to tell them apart is to re-check whether
            // a row now actually exists for this (order, offset).
            if ($this->hasReminderBeenSent((int) $order->id, $offset)) {
                // A concurrent run's insert won this race and already logged the exact same reminder.
                // The email above may have gone out twice in that narrow window, but the log is not
                // corrupted, the other runner already recorded it, and nothing here is allowed to
                // throw -- so this one, specific, expected race is benign and still counts as sent.
                Craft::warning("Payment reminder log insert skipped for order {$order->id} at offset {$offset} (already logged by a concurrent run): {$e->getMessage()}", 'b2b-commerce');

                return true;
            }

            // No row was written by anyone: this is a real failure (e.g. a foreign-key violation
            // because the order vanished), not the benign duplicate race. The row was never recorded,
            // so a later run needs to see this as still due and retry it.
            Craft::error("Payment reminder log insert failed for order {$order->id} at offset {$offset}: {$e->getMessage()}", 'b2b-commerce');

            return false;
        } catch (\Throwable $e) {
            // Any OTHER insert failure (e.g. a persistent DB problem) must NOT be reported as sent: the
            // row was never written, so a later run needs to see this as still due and retry it. Unlike
            // the benign race above, this is a real failure -- log it as an error and tell the caller.
            Craft::error("Payment reminder log insert failed for order {$order->id} at offset {$offset}: {$e->getMessage()}", 'b2b-commerce');

            return false;
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
