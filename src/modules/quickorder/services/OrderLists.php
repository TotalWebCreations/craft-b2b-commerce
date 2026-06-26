<?php

namespace totalwebcreations\b2bcommerce\modules\quickorder\services;

use Craft;
use craft\commerce\db\Table;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\Db;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Shared, company-scoped order lists: named collections of purchasables a company
 * keeps around to drop into a cart in one go. Lists and their items are plain
 * records (no element overhead); every method verifies the list belongs to the
 * acting company before touching it.
 */
class OrderLists extends Component
{
    private const LISTS_TABLE = '{{%b2b_order_lists}}';
    private const ITEMS_TABLE = '{{%b2b_order_list_items}}';

    /**
     * Returns every list for a company with its item count, resolved in a single
     * grouped query (no per-list count round-trips).
     *
     * @return array<int, array{id: int, name: string, createdByUserId: ?int, itemCount: int}>
     */
    public function getLists(int $companyId): array
    {
        $rows = (new Query())
            ->select([
                'id' => 'lists.id',
                'name' => 'lists.name',
                'createdByUserId' => 'lists.createdByUserId',
                'itemCount' => 'COUNT(items.id)',
            ])
            ->from(['lists' => self::LISTS_TABLE])
            ->leftJoin(['items' => self::ITEMS_TABLE], '[[items.listId]] = [[lists.id]]')
            ->where(['lists.companyId' => $companyId])
            ->groupBy(['lists.id', 'lists.name', 'lists.createdByUserId'])
            ->orderBy(['lists.name' => SORT_ASC])
            ->all();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'createdByUserId' => $row['createdByUserId'] !== null ? (int) $row['createdByUserId'] : null,
            'itemCount' => (int) $row['itemCount'],
        ], $rows);
    }

    public function createList(Company $company, string $name, ?int $userId): int
    {
        $name = $this->normalizeName($name);

        Db::insert(self::LISTS_TABLE, [
            'companyId' => $company->id,
            'name' => $name,
            'createdByUserId' => $userId,
        ]);

        return (int) Craft::$app->getDb()->getLastInsertID();
    }

    public function renameList(Company $company, int $listId, string $name): void
    {
        $this->requireOwnedList($company, $listId);
        $name = $this->normalizeName($name);

        Db::update(self::LISTS_TABLE, ['name' => $name], ['id' => $listId]);
    }

    public function deleteList(Company $company, int $listId): void
    {
        $this->requireOwnedList($company, $listId);

        Db::delete(self::LISTS_TABLE, ['id' => $listId]);
    }

    /**
     * Sets the quantity for a purchasable on a list. A quantity of zero or less
     * removes the row; otherwise the row is upserted against the (listId,
     * purchasableId) unique index. The purchasable must exist.
     */
    public function setItem(Company $company, int $listId, int $purchasableId, int $qty): void
    {
        $this->requireOwnedList($company, $listId);

        if ($qty <= 0) {
            Db::delete(self::ITEMS_TABLE, ['listId' => $listId, 'purchasableId' => $purchasableId]);

            return;
        }

        if (!$this->purchasableExists($purchasableId)) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This purchasable does not exist.')
            );
        }

        Db::upsert(self::ITEMS_TABLE, [
            'listId' => $listId,
            'purchasableId' => $purchasableId,
            'qty' => $qty,
        ], [
            'qty' => $qty,
        ]);
    }

    /**
     * Returns the items on a list, joined to the purchasables table for the SKU and
     * description in a single query. Items whose purchasable no longer exists are
     * excluded here (the inner join drops them); addListToCart surfaces those.
     *
     * @return array<int, array{purchasableId: int, qty: int, sku: string, description: ?string}>
     */
    public function getItems(Company $company, int $listId): array
    {
        $this->requireOwnedList($company, $listId);

        $rows = (new Query())
            ->select([
                'purchasableId' => 'items.purchasableId',
                'qty' => 'items.qty',
                'sku' => 'purchasables.sku',
                'description' => 'purchasables.description',
            ])
            ->from(['items' => self::ITEMS_TABLE])
            ->innerJoin(['purchasables' => Table::PURCHASABLES], '[[purchasables.id]] = [[items.purchasableId]]')
            ->where(['items.listId' => $listId])
            ->orderBy(['items.id' => SORT_ASC])
            ->all();

        return array_map(static fn (array $row): array => [
            'purchasableId' => (int) $row['purchasableId'],
            'qty' => (int) $row['qty'],
            'sku' => (string) $row['sku'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
        ], $rows);
    }

    /**
     * Copies every available item of a list into the cart, reusing the quick-order
     * add-path so line-item vetoes are honoured. Missing or unavailable purchasables
     * surface as per-position errors. An empty list adds nothing.
     *
     * @return array{added: int, errors: array<int, string>}
     */
    public function addListToCart(Order $cart, Company $company, int $listId): array
    {
        $this->requireOwnedList($company, $listId);

        $items = (new Query())
            ->select([
                'purchasableId' => 'items.purchasableId',
                'qty' => 'items.qty',
                'sku' => 'purchasables.sku',
            ])
            ->from(['items' => self::ITEMS_TABLE])
            ->leftJoin(['purchasables' => Table::PURCHASABLES], '[[purchasables.id]] = [[items.purchasableId]]')
            ->where(['items.listId' => $listId])
            ->orderBy(['items.id' => SORT_ASC])
            ->all();

        if ($items === []) {
            return ['added' => 0, 'errors' => []];
        }

        $purchasables = Commerce::getInstance()->getPurchasables();
        $quickOrder = Plugin::getInstance()->quickOrder;

        $errors = [];
        $added = 0;

        foreach (array_values($items) as $index => $item) {
            $position = $index + 1;
            $purchasableId = (int) $item['purchasableId'];
            $qty = (int) $item['qty'];
            $label = $item['sku'] !== null ? (string) $item['sku'] : (string) $purchasableId;

            $purchasable = $purchasables->getPurchasableById($purchasableId);

            if ($purchasable === null || !$purchasable->getIsAvailable()) {
                $errors[$position] = Craft::t('b2b-commerce', 'SKU "{sku}" is not available', ['sku' => $label]);

                continue;
            }

            $error = $quickOrder->addResolvedPurchasable($cart, $purchasableId, $qty, $label);

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

    private function normalizeName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException(Craft::t('b2b-commerce', 'A list name is required.'));
        }

        if (mb_strlen($name) > 255) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'A list name may not be longer than 255 characters.')
            );
        }

        return $name;
    }

    private function purchasableExists(int $purchasableId): bool
    {
        return (new Query())
            ->from(Table::PURCHASABLES)
            ->where(['id' => $purchasableId])
            ->exists();
    }

    private function requireOwnedList(Company $company, int $listId): void
    {
        $companyId = (new Query())
            ->select(['companyId'])
            ->from(self::LISTS_TABLE)
            ->where(['id' => $listId])
            ->scalar();

        if ($companyId === false || (int) $companyId !== $company->id) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This list does not belong to this company.')
            );
        }
    }
}
