<?php

use craft\base\ElementInterface;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\CompanyRole;
use totalwebcreations\b2bcommerce\Plugin;

/**
 * Elements created during a test, hard-deleted afterwards.
 *
 * @var array<int, ElementInterface> $GLOBALS['b2bTrackedElements']
 */
$GLOBALS['b2bTrackedElements'] = [];

/**
 * Registers an element for automatic hard-delete in afterEach.
 */
function trackElement(ElementInterface $element): void
{
    $GLOBALS['b2bTrackedElements'][] = $element;
}

/**
 * Hard-deletes every tracked element and resets the tracker.
 */
function deleteTrackedElements(): void
{
    if ($GLOBALS['b2bTrackedElements'] === []) {
        return;
    }

    $elementsService = craftApp()->getElements();

    foreach (array_reverse($GLOBALS['b2bTrackedElements']) as $element) {
        $elementsService->deleteElement($element, true);
    }

    $GLOBALS['b2bTrackedElements'] = [];
}

/**
 * Creates and saves a tracked Company with a unique title.
 */
function createTestCompany(string $status = 'approved', string $title = 'Test Co'): Company
{
    $company = new Company();
    $company->title = $title . ' ' . uniqid();
    $company->companyStatus = $status;

    if (!craftApp()->getElements()->saveElement($company)) {
        throw new RuntimeException('Could not save test company: ' . implode(', ', $company->getFirstErrors()));
    }

    trackElement($company);

    return $company;
}

/**
 * Returns a reusable throwaway product type, creating it on first use. Product
 * types live in project config, so it is created once and reused across tests
 * rather than rebuilt (and torn down) per test.
 */
function quickOrderProductType(): ProductType
{
    $handle = 'b2bQuickOrderTest';
    $existing = Commerce::getInstance()->getProductTypes()->getProductTypeByHandle($handle);

    if ($existing !== null) {
        return $existing;
    }

    $type = new ProductType();
    $type->name = 'B2B Quick Order Test';
    $type->handle = $handle;
    $type->maxVariants = 1;
    $type->hasVariantTitleField = false;
    $type->variantTitleFormat = '{sku}';

    $siteSettings = [];

    foreach (craftApp()->getSites()->getAllSites() as $site) {
        $siteSetting = new ProductTypeSite();
        $siteSetting->siteId = $site->id;
        $siteSetting->hasUrls = false;
        $siteSettings[$site->id] = $siteSetting;
    }

    $type->setSiteSettings($siteSettings);

    if (!Commerce::getInstance()->getProductTypes()->saveProductType($type)) {
        throw new RuntimeException('Could not save test product type: ' . implode(', ', $type->getErrorSummary(true)));
    }

    return $type;
}

/**
 * Creates and saves a tracked product with a single variant carrying the given SKU.
 */
function createTestVariant(string $sku, float $price = 10.0, bool $enabled = true): Variant
{
    $product = new Product();
    $product->typeId = quickOrderProductType()->id;
    $product->title = 'Quick order ' . $sku;
    $product->enabled = $enabled;

    if (!craftApp()->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save test product: ' . implode(', ', $product->getErrorSummary(true)));
    }

    trackElement($product);

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->setBasePrice($price);
    $variant->setPrimaryOwner($product);
    $variant->setOwner($product);

    if (!craftApp()->getElements()->saveElement($variant)) {
        throw new RuntimeException('Could not save test variant: ' . implode(', ', $variant->getErrorSummary(true)));
    }

    trackElement($variant);

    return $variant;
}

/**
 * Absolute path to the file-transport mailbox of the dev site.
 */
function mailDir(): string
{
    return dirname(__DIR__, 3) . '/b2b-dev/storage/runtime/mail';
}

/**
 * Number of .eml files currently sitting in the dev-site mailbox.
 */
function mailCount(): int
{
    return count(glob(mailDir() . '/*.eml') ?: []);
}

/**
 * Snapshot of the .eml files currently in the dev mailbox, for diffing against
 * a later state to isolate the mail a single action produced. Sorting by
 * filemtime is unreliable at 1-second resolution, so tests diff the file set.
 *
 * @return string[]
 */
function mailSnapshot(): array
{
    return glob(mailDir() . '/*.eml') ?: [];
}

/**
 * Concatenated, quoted-printable decoded bodies of every .eml written since the
 * given snapshot, so soft-wrapped long URLs and encoded query separators match
 * as plain text.
 *
 * @param string[] $snapshot
 */
function decodedMailSince(array $snapshot): string
{
    $new = array_diff(mailSnapshot(), $snapshot);

    $body = '';

    foreach ($new as $file) {
        $body .= quoted_printable_decode((string) file_get_contents($file)) . "\n";
    }

    return $body;
}

/**
 * Raw contents of the most recently written .eml file in the dev mailbox.
 */
function newestMailBody(): string
{
    $files = glob(mailDir() . '/*.eml') ?: [];

    if ($files === []) {
        return '';
    }

    usort($files, fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

    return (string) file_get_contents($files[0]);
}

/**
 * Creates and saves a tracked User with a unique username and the given email.
 */
function createTestUser(string $email, bool $active = true): User
{
    $user = new User();
    $user->email = $email;
    $user->username = 'test_' . uniqid();
    $user->active = $active;

    if (!craftApp()->getElements()->saveElement($user)) {
        throw new RuntimeException('Could not save test user: ' . implode(', ', $user->getFirstErrors()));
    }

    trackElement($user);

    return $user;
}

/**
 * Creates and saves a tracked cart order carrying a single line item.
 */
function quoteCartWithItem(): Order
{
    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save quote cart: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    $variant = createTestVariant('QUOTE-' . substr(uniqid(), -6));
    Plugin::getInstance()->quickOrder->addResolvedPurchasable($order, $variant->id, 1, $variant->sku);
    craftApp()->getElements()->saveElement($order);

    return $order;
}

/**
 * Creates a tracked user attached to a tracked company with the given status.
 *
 * @return array{0: User, 1: Company}
 */
function quoteMember(string $status = Company::STATUS_APPROVED): array
{
    $company = createTestCompany($status, 'Quote Co');
    $user = createTestUser('quote_' . uniqid() . '@example.test');
    Plugin::getInstance()->companyMembers->addUserToCompany($user->id, $company->id, CompanyRole::Admin);

    return [$user, $company];
}

/**
 * Runs the callback while pretending to be a front-end (non-console) request,
 * restoring the console flag afterwards. Front-end guards keyed on the request
 * type only fire in this window.
 */
function asSiteRequest(callable $callback): void
{
    $request = craftApp()->getRequest();
    $request->setIsConsoleRequest(false);

    try {
        $callback();
    } finally {
        $request->setIsConsoleRequest(true);
    }
}

/**
 * Reads the quote row for the given order straight from the table.
 *
 * @return array<string, mixed>|null
 */
function quoteRow(int $orderId): ?array
{
    return (new Query())
        ->from('{{%b2b_quotes}}')
        ->where(['orderId' => $orderId])
        ->one() ?: null;
}
