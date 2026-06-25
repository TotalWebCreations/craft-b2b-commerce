<?php

namespace totalwebcreations\b2bcommerce\modules\quickorder\services;

use Craft;
use craft\commerce\db\Table;
use craft\commerce\elements\Order;
use craft\commerce\models\LineItem;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use totalwebcreations\b2bcommerce\modules\quickorder\parsers\SkuLineParser;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\InvalidArgumentException;

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
        $purchasables = Commerce::getInstance()->getPurchasables();

        $added = 0;

        foreach ($lines as $lineNumber => $line) {
            $purchasableId = $purchasableIdsBySku[mb_strtolower($line['sku'])] ?? null;

            if ($purchasableId === null) {
                $errors[$lineNumber] = Craft::t('b2b-commerce', 'Unknown SKU "{sku}"', ['sku' => $line['sku']]);

                continue;
            }

            // Deliberate mild N+1: each hit is loaded individually because getIsAvailable()
            // needs a fully hydrated purchasable. Quick-order batches are small, so the extra
            // queries are acceptable here.
            $purchasable = $purchasables->getPurchasableById($purchasableId);

            if ($purchasable === null || !$purchasable->getIsAvailable()) {
                $errors[$lineNumber] = Craft::t('b2b-commerce', 'SKU "{sku}" is not available', ['sku' => $line['sku']]);

                continue;
            }

            $error = $this->addResolvedPurchasable($cart, $purchasableId, $line['qty'], $line['sku']);

            if ($error !== null) {
                $errors[$lineNumber] = $error;

                continue;
            }

            $added++;
        }

        if ($added > 0) {
            Craft::$app->getElements()->saveElement($cart);
        }

        ksort($errors);

        return ['added' => $added, 'errors' => $errors];
    }

    /**
     * Copies every still-available line item of a completed source order into the cart.
     * The actor must own the source order or share its company, and the source must be
     * completed. Unavailable or deleted purchasables surface as per-position errors.
     *
     * @return array{added: int, errors: array<int, string>}
     */
    public function reorder(Order $cart, Order $source, User $actor): array
    {
        if (!$source->isCompleted) {
            throw new InvalidArgumentException(Craft::t('b2b-commerce', 'Only completed orders can be reordered.'));
        }

        if (!$this->actorMayReorder($source, $actor)) {
            throw new InvalidArgumentException(Craft::t('b2b-commerce', 'You are not allowed to reorder this order.'));
        }

        $errors = [];
        $added = 0;

        foreach (array_values($source->getLineItems()) as $index => $lineItem) {
            $position = $index + 1;
            $description = $lineItem->getDescription();
            $purchasable = $lineItem->purchasableId ? $lineItem->getPurchasable() : null;

            if ($purchasable === null || !$purchasable->getIsAvailable()) {
                $errors[$position] = Craft::t('b2b-commerce', '"{description}" is no longer available', ['description' => $description]);

                continue;
            }

            $error = $this->addResolvedPurchasable($cart, (int) $lineItem->purchasableId, (int) $lineItem->qty, $description);

            if ($error !== null) {
                $errors[$position] = $error;

                continue;
            }

            $added++;
        }

        if ($added > 0) {
            Craft::$app->getElements()->saveElement($cart);
        }

        ksort($errors);

        return ['added' => $added, 'errors' => $errors];
    }

    /**
     * Merges the requested quantity into the cart for the given purchasable and runs the
     * add through Commerce. Returns null on success, or a translated message when a
     * line-item veto blocks the add. The label seeds the neutral fallback message.
     */
    private function addResolvedPurchasable(Order $cart, int $purchasableId, int $qty, string $label): ?string
    {
        $lineItem = Commerce::getInstance()->getLineItems()->resolveLineItem($cart, $purchasableId, []);
        $lineItem->qty = $lineItem->id ? $lineItem->qty + $qty : $qty;

        $cart->addLineItem($lineItem);

        if (in_array($lineItem, $cart->getLineItems(), true)) {
            return null;
        }

        return $this->firstLineItemError($lineItem)
            ?? Craft::t('b2b-commerce', '"{sku}" could not be added.', ['sku' => $label]);
    }

    /**
     * Returns the first validation error across every attribute of the line item, or null
     * when it carries none. Used for the veto message so a blocked add reports the reason
     * the veto actually set rather than assuming a specific attribute.
     */
    private function firstLineItemError(LineItem $lineItem): ?string
    {
        $firstErrors = $lineItem->getFirstErrors();

        if ($firstErrors === []) {
            return null;
        }

        return (string) reset($firstErrors);
    }

    /**
     * Reports whether the actor may reorder the source: they either own it, or both the
     * order and the actor belong to the same (non-null) company.
     */
    private function actorMayReorder(Order $source, User $actor): bool
    {
        if ($source->getCustomer()?->id === $actor->id) {
            return true;
        }

        $sourceCompanyId = $source->b2bCompany?->id;
        $actorCompanyId = Plugin::getInstance()->companyMembers->getCompanyForUser($actor->id)?->id;

        return $sourceCompanyId !== null && $sourceCompanyId === $actorCompanyId;
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
