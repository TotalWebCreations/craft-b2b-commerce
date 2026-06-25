<?php

namespace totalwebcreations\b2bcommerce\modules\quickorder\services;

use Craft;
use craft\commerce\db\Table;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use totalwebcreations\b2bcommerce\modules\quickorder\parsers\SkuLineParser;
use yii\base\Component;

class QuickOrder extends Component
{
    /**
     * Parses the pasted input, resolves every SKU to a purchasable and adds the
     * requested quantities to the cart. Parser errors and resolution errors are
     * merged into a single map keyed by the original 1-based line number.
     *
     * @return array{added: int, errors: array<int, string>}
     */
    public function addToCart(Order $cart, string $input): array
    {
        $parsed = (new SkuLineParser())->parse($input);
        $errors = $parsed['errors'];
        $lines = $parsed['lines'];

        if ($lines === []) {
            ksort($errors);

            return ['added' => 0, 'errors' => $errors];
        }

        $purchasableIdsBySku = $this->resolvePurchasableIds($lines);
        $lineItems = Commerce::getInstance()->getLineItems();
        $purchasables = Commerce::getInstance()->getPurchasables();

        $added = 0;

        foreach ($lines as $lineNumber => $line) {
            $purchasableId = $purchasableIdsBySku[mb_strtolower($line['sku'])] ?? null;

            if ($purchasableId === null) {
                $errors[$lineNumber] = Craft::t('b2b-commerce', 'Unknown SKU "{sku}"', ['sku' => $line['sku']]);

                continue;
            }

            $purchasable = $purchasables->getPurchasableById($purchasableId);

            if ($purchasable === null || !$purchasable->getIsAvailable()) {
                $errors[$lineNumber] = Craft::t('b2b-commerce', 'SKU "{sku}" is not available', ['sku' => $line['sku']]);

                continue;
            }

            $lineItem = $lineItems->resolveLineItem($cart, $purchasableId, []);
            $lineItem->qty = $lineItem->id ? $lineItem->qty + $line['qty'] : $line['qty'];

            $cart->addLineItem($lineItem);

            if (!in_array($lineItem, $cart->getLineItems(), true)) {
                $errors[$lineNumber] = $lineItem->getFirstError('purchasableId')
                    ?? Craft::t('b2b-commerce', 'You need an approved business account to order.');

                continue;
            }

            $added++;
        }

        Craft::$app->getElements()->saveElement($cart);

        ksort($errors);

        return ['added' => $added, 'errors' => $errors];
    }

    /**
     * Resolves every SKU in the parsed lines to a purchasable ID in a single query,
     * mapped case-insensitively (SKUs live on the commerce_purchasables table).
     *
     * @param array<int, array{sku: string, qty: int}> $lines
     * @return array<string, int> lowercased SKU => purchasable ID
     */
    private function resolvePurchasableIds(array $lines): array
    {
        $loweredSkus = [];

        foreach ($lines as $line) {
            $loweredSkus[mb_strtolower($line['sku'])] = true;
        }

        $placeholders = [];
        $params = [];

        foreach (array_keys($loweredSkus) as $index => $sku) {
            $placeholder = ":sku{$index}";
            $placeholders[] = $placeholder;
            $params[$placeholder] = $sku;
        }

        $rows = (new Query())
            ->select(['id', 'sku'])
            ->from(Table::PURCHASABLES)
            ->where('LOWER([[sku]]) IN (' . implode(', ', $placeholders) . ')', $params)
            ->all();

        $map = [];

        foreach ($rows as $row) {
            $map[mb_strtolower($row['sku'])] = (int) $row['id'];
        }

        return $map;
    }
}
