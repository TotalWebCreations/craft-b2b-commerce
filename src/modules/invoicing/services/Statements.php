<?php

namespace totalwebcreations\b2bcommerce\modules\invoicing\services;

use craft\commerce\Plugin as Commerce;
use DateTimeImmutable;
use totalwebcreations\b2bcommerce\enums\AgingBucket;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;

/**
 * Builds a company's account statement: its outstanding invoice orders bucketed by how far past
 * due they are. Computed on demand from {@see CreditBalance::getOutstandingOrders()} (which already
 * excludes settled, paid and overpaid orders) and {@see AgingBucket}; there is no statement table.
 */
class Statements extends Component
{
    /**
     * @return array{
     *     companyId: int,
     *     currency: ?string,
     *     asOf: DateTimeImmutable,
     *     totalOutstanding: float,
     *     buckets: array<string, float>,
     *     lines: array<int, array{
     *         orderId: int,
     *         number: ?string,
     *         reference: ?string,
     *         dateOrdered: ?\DateTimeInterface,
     *         dueDate: ?\DateTimeInterface,
     *         daysPastDue: int,
     *         balance: float,
     *         bucket: string
     *     }>
     * }
     */
    public function getStatement(int $companyId, ?DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new DateTimeImmutable('now');

        $buckets = [
            AgingBucket::Current->value => 0.0,
            AgingBucket::Days1To30->value => 0.0,
            AgingBucket::Days31To60->value => 0.0,
            AgingBucket::Days61To90->value => 0.0,
            AgingBucket::Days90Plus->value => 0.0,
        ];

        $lines = [];
        $total = 0.0;

        foreach (Plugin::getInstance()->creditBalance->getOutstandingOrders($companyId) as $order) {
            $balance = $order->getOutstandingBalance();
            $dueDate = $order->b2bPaymentDueDate;
            $daysPastDue = AgingBucket::daysPastDue($dueDate, $asOf);
            $bucket = AgingBucket::forDaysPastDue($daysPastDue);

            $buckets[$bucket->value] += $balance;
            $total += $balance;

            $lines[] = [
                'orderId' => (int) $order->id,
                'number' => $order->number,
                'reference' => $order->reference ?: $order->getShortNumber(),
                'dateOrdered' => $order->dateOrdered,
                'dueDate' => $dueDate,
                'daysPastDue' => $daysPastDue,
                'total' => $order->getTotalPrice(),
                'balance' => $balance,
                'bucket' => $bucket->value,
            ];
        }

        $currency = Commerce::getInstance()->getStores()->getPrimaryStore()?->getCurrency()?->getCode();

        return [
            'companyId' => $companyId,
            'currency' => $currency,
            'asOf' => $asOf,
            'totalOutstanding' => $total,
            'buckets' => $buckets,
            'lines' => $lines,
        ];
    }
}
