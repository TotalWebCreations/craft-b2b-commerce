<?php

namespace totalwebcreations\b2bcommerce\modules\companies\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\taxidvalidators\EuVatIdValidator;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;

/**
 * VAT-id validation on top of Craft Commerce's native VIES support.
 *
 * Commerce's own `Vat::isValidVatId()` cannot report a VIES outage: both the service and
 * `EuVatIdValidator` catch every exception internally and return false, so "VIES is down" and
 * "this VAT id does not exist" collapse into the same answer. Distinguishing the two is the whole
 * point of the lenient/strict outage policy, so this service reuses Commerce's building blocks —
 * `EuVatIdValidator::validateFormat()`, the VIES REST endpoint (`EuVatIdValidator::API_URL`) and
 * Commerce's exact cache key — but performs the existence call itself so a transport failure can
 * surface as null instead of false.
 *
 * Cache behaviour (mirrors Commerce exactly): only VALID ids are cached, under Commerce's own
 * `commerce:validVatId:{id}` key and with Craft's default cache duration (the `cacheDuration`
 * general config setting, 24 hours by default). A VAT id validated here is therefore instantly
 * known-valid to Commerce's tax adjuster (which checks the same key before calling VIES) and vice
 * versa. Invalid and undecidable results are never cached, so a typo never sticks.
 */
class TaxIdValidation extends Component
{
    /**
     * Commerce's cache key prefix, shared verbatim by `craft\commerce\services\Vat` and
     * `craft\commerce\adjusters\Tax::organizationTaxIdIsValidTaxId()`.
     */
    public const CACHE_KEY_PREFIX = 'commerce:validVatId:';

    /**
     * Test seam: replaces the live VIES existence lookup. Receives the full VAT id and must
     * return true (exists), false (does not exist) or null (VIES unreachable). Tests set this so
     * the suite never performs network calls.
     *
     * @var (callable(string): ?bool)|null
     */
    public $existenceLookup = null;

    /**
     * Per-request memo for the checkout passthrough's company lookup, keyed by customer id, so a
     * recalculating order that saves several times in one request queries the company only once.
     *
     * @var array<int, ?Company>
     */
    private array $companyForUserMemo = [];

    /**
     * Validates a VAT id: true = valid, false = definitively invalid (bad format or VIES says it
     * does not exist), null = undecidable because VIES is unreachable.
     *
     * Pass `$refresh: true` to bypass the known-valid cache and force a fresh VIES lookup (used
     * by the revalidate console command).
     */
    public function validate(string $taxId, bool $refresh = false): ?bool
    {
        $taxId = trim($taxId);

        if ($taxId === '') {
            return false;
        }

        if (!(new EuVatIdValidator())->validateFormat($taxId)) {
            return false;
        }

        $cache = Craft::$app->getCache();
        $cacheKey = self::CACHE_KEY_PREFIX . $taxId;

        if (!$refresh && $cache->exists($cacheKey)) {
            return true;
        }

        $exists = $this->lookUpExistence($taxId);

        if ($exists === null) {
            return null;
        }

        if (!$exists) {
            $cache->delete($cacheKey);

            return false;
        }

        $cache->set($cacheKey, '1');

        return true;
    }

    /**
     * Fills the order's shipping and billing address `organizationTaxId` with the customer's
     * company VAT id when the customer left the field empty, so Commerce's tax adjuster can apply
     * a `removeVatIncluded` tax rate (automatic reverse charge) to B2B customers.
     *
     * Called from Order::EVENT_BEFORE_SAVE: the mutation happens on the in-memory address
     * elements only, which is both sufficient and loop-safe. Commerce's `Order::afterSave()`
     * first runs `recalculate()` (the tax adjuster reads `organizationTaxId` from these same
     * in-memory elements via `_getTaxAddress()`) and then persists them itself, so no extra
     * `saveElement()` call is needed here.
     */
    public function applyCompanyTaxIdToOrderAddresses(Order $order): void
    {
        $request = Craft::$app->getRequest();

        // Storefront-only: never touch console or control-panel order saves.
        if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
            return;
        }

        if ($order->isCompleted) {
            return;
        }

        $customer = $order->getCustomer();

        if ($customer === null) {
            return;
        }

        $company = $this->companyForUser($customer->id);

        if ($company === null) {
            return;
        }

        $taxId = trim((string) $company->taxId);

        if ($taxId === '') {
            return;
        }

        foreach ([$order->getShippingAddress(), $order->getBillingAddress()] as $address) {
            if ($address === null) {
                continue;
            }

            if (trim((string) $address->organizationTaxId) !== '') {
                continue;
            }

            $address->organizationTaxId = $taxId;
        }
    }

    private function companyForUser(int $userId): ?Company
    {
        if (!array_key_exists($userId, $this->companyForUserMemo)) {
            $this->companyForUserMemo[$userId] = Plugin::getInstance()->companyMembers->getCompanyForUser($userId);
        }

        return $this->companyForUserMemo[$userId];
    }

    private function lookUpExistence(string $taxId): ?bool
    {
        if ($this->existenceLookup !== null) {
            return ($this->existenceLookup)($taxId);
        }

        // Mirror Commerce's EuVatIdValidator::_splitNumber(): the whole id is uppercased before
        // splitting, so ids whose number part carries letters (NL, ES, IE) are sent uppercased.
        $countryCode = strtoupper(substr($taxId, 0, 2));
        $number = strtoupper(substr($taxId, 2));

        try {
            $response = Craft::createGuzzleClient()->post(EuVatIdValidator::API_URL, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode([
                    'countryCode' => $countryCode,
                    'vatNumber' => $number,
                ]),
            ]);
        } catch (\Throwable $e) {
            Craft::warning("VIES lookup failed for VAT ID \"{$taxId}\": {$e->getMessage()}", 'b2b-commerce');

            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $body = json_decode((string) $response->getBody(), true);

        // A 200 without the expected payload is a service anomaly, not a verdict on the VAT id.
        if (!is_array($body) || !array_key_exists('valid', $body)) {
            return null;
        }

        return $body['valid'] === true;
    }
}
