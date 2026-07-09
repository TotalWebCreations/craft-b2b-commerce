<?php

namespace totalwebcreations\b2bcommerce\modules\departments\services;

use Craft;
use craft\commerce\elements\Order;
use DateTime;
use Throwable;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\Exception;

/**
 * Hard department-budget enforcement at order completion — a new backstop layered AFTER the
 * per-member budget guard ({@see \totalwebcreations\b2bcommerce\modules\budgets\services\BudgetEnforcer})
 * and BEFORE the credit-limit check. It caps the COMBINED spend of the completing member's department
 * (see {@see DepartmentBudget}); the per-member and department caps are independent and both must pass.
 *
 * Storefront-only, like every other completion guard: console and control-panel completions are the
 * deliberate merchant override.
 *
 * Locking mirrors BudgetEnforcer but scopes to the department: two members of the same department
 * completing concurrently could each read the same pre-completion aggregate spend, both fit, and
 * together overshoot. A per-department lock (b2b-dept-budget-{departmentId}) is taken BEFORE reading
 * the spend and handed to {@see releaseDepartmentBudgetLock()} on EVENT_AFTER_COMPLETE_ORDER
 * (registered AFTER OrderCompanyLink::linkCompany) so it spans BOTH the completion save and the
 * b2b_order_company link row that makes the order count towards spend. A lock held past its use is
 * released at request teardown — it never fails open, and can only cause a temporary, self-healing
 * refusal (see BudgetEnforcer for the full fail-safe rationale).
 */
class DepartmentBudgetEnforcer extends Component
{
    private const LOCK_TIMEOUT = 5;

    /** @var array<int, string> */
    private array $heldLocks = [];

    public function enforceDepartmentBudget(Order $order): void
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
            return;
        }

        $customer = $order->getCustomer();

        if ($customer === null) {
            return;
        }

        if (Plugin::getInstance()->companyMembers->getCompanyForUser($customer->id) === null) {
            return;
        }

        $department = Plugin::getInstance()->departments->getDepartmentForUser($customer->id);

        if ($department === null) {
            return;
        }

        // A null budget means unlimited: nothing to enforce, so never take a lock.
        if ($department['budgetAmount'] === null) {
            return;
        }

        $mutex = Craft::$app->getMutex();
        $lockName = "b2b-dept-budget-{$department['id']}";

        if (!$mutex->acquire($lockName, self::LOCK_TIMEOUT)) {
            $message = Craft::t('b2b-commerce', 'Could not verify the department spending budget. Please try again.');

            $order->addError('customerId', $message);

            throw new Exception($message);
        }

        try {
            if (!Plugin::getInstance()->departmentBudget->canAfford($department, (float) $order->getTotalPrice(), new DateTime('now'))) {
                $message = Craft::t('b2b-commerce', 'This order exceeds the department spending budget.');

                $order->addError('customerId', $message);

                throw new Exception($message);
            }
        } catch (Throwable $throwable) {
            $mutex->release($lockName);

            throw $throwable;
        }

        $this->heldLocks[$order->id] = $lockName;
    }

    public function releaseDepartmentBudgetLock(Order $order): void
    {
        $lockName = $this->heldLocks[$order->id] ?? null;

        if ($lockName === null) {
            return;
        }

        unset($this->heldLocks[$order->id]);

        Craft::$app->getMutex()->release($lockName);
    }
}
