<?php

namespace totalwebcreations\b2bcommerce\gateways;

use Craft;
use craft\commerce\base\Gateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\elements\Order;
use craft\commerce\errors\NotImplementedException;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\payments\OffsitePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\responses\Manual as ManualRequestResponse;
use craft\commerce\models\Transaction;
use craft\web\Response as WebResponse;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * InvoiceGateway is an offline "pay on account" gateway that mirrors the
 * transaction semantics of Commerce's Manual gateway (authorize-only; the order
 * completes unpaid and funds are captured later out of band). It is only offered
 * to approved companies that are allowed to pay on invoice and have credit room.
 *
 * @property-read null|string $settingsHtml
 */
class InvoiceGateway extends Gateway
{
    public static function displayName(): string
    {
        return Craft::t('b2b-commerce', 'Pay on account');
    }

    public function getPaymentFormHtml(array $params): ?string
    {
        return '';
    }

    public function getPaymentFormModel(): BasePaymentForm
    {
        return new OffsitePaymentForm();
    }

    public function getSettingsHtml(): ?string
    {
        return null;
    }

    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        return new ManualRequestResponse();
    }

    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        return new ManualRequestResponse();
    }

    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }

    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }

    public function createPaymentSource(BasePaymentForm $sourceData, int $customerId): PaymentSource
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }

    public function deletePaymentSource(string $token): bool
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }

    public function getPaymentTypeOptions(): array
    {
        return [
            'authorize' => Craft::t('commerce', 'Authorize Only (Manually Capture)'),
        ];
    }

    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }

    public function processWebHook(): WebResponse
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }

    public function refund(Transaction $transaction): RequestResponseInterface
    {
        return new ManualRequestResponse();
    }

    public function supportsAuthorize(): bool
    {
        return true;
    }

    public function supportsCapture(): bool
    {
        return true;
    }

    public function supportsCompleteAuthorize(): bool
    {
        return false;
    }

    public function supportsCompletePurchase(): bool
    {
        return false;
    }

    public function supportsPaymentSources(): bool
    {
        return false;
    }

    public function supportsPurchase(): bool
    {
        return false;
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function supportsPartialRefund(): bool
    {
        return true;
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function availableForUseWithOrder(Order $order): bool
    {
        if (!Plugin::getInstance()->getSettings()->enableInvoicing) {
            return false;
        }

        $customer = $order->getCustomer();

        if ($customer === null) {
            return false;
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($customer->id);

        if ($company === null) {
            return false;
        }

        if ($company->companyStatus !== Company::STATUS_APPROVED || !$company->allowInvoicePayment) {
            return false;
        }

        if (!$this->hasCreditRoom($order, $company)) {
            return false;
        }

        return parent::availableForUseWithOrder($order);
    }

    private function hasCreditRoom(Order $order, Company $company): bool
    {
        return Plugin::getInstance()->creditBalance->canCover($company->id, (float)$order->getTotalPrice());
    }
}
