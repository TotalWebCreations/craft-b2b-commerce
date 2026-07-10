<?php

use craft\base\ElementInterface;
use craft\commerce\elements\conditions\products\CatalogPricingRuleProductCondition;
use craft\commerce\elements\conditions\products\ProductTypeConditionRule;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\Json;
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
 * Saves an empty cart order for the given customer (or a guest when null) and
 * tracks it for hard-delete afterwards.
 */
function createTestOrder(?User $customer): Order
{
    $order = new Order();
    $order->number = md5(uniqid((string) mt_rand(), true));

    if ($customer !== null) {
        $order->setCustomer($customer);
    }

    if (!craftApp()->getElements()->saveElement($order)) {
        throw new RuntimeException('Could not save test order: ' . implode(', ', $order->getFirstErrors()));
    }

    trackElement($order);

    return $order;
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
 * A second throwaway product type, so a catalog condition scoped to the default
 * quick-order type refuses a variant built under this one. Shared by the Integration
 * and Http suites (both unconditionally load this file), so it lives here rather
 * than in a single test file.
 */
function catalogOtherProductType(): ProductType
{
    $handle = 'b2bCatalogOtherTest';
    $existing = Commerce::getInstance()->getProductTypes()->getProductTypeByHandle($handle);

    if ($existing !== null) {
        return $existing;
    }

    $type = new ProductType();
    $type->name = 'B2B Catalog Other Test';
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
        throw new RuntimeException('Could not save catalog test product type: ' . implode(', ', $type->getErrorSummary(true)));
    }

    return $type;
}

/**
 * Creates a tracked single-variant product under the given type, returning the variant.
 */
function createTestVariantOfType(ProductType $type, string $sku, float $price = 10.0): Variant
{
    $product = new Product();
    $product->typeId = $type->id;
    $product->title = 'Catalog ' . $sku;
    $product->enabled = true;

    if (!craftApp()->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save catalog test product: ' . implode(', ', $product->getErrorSummary(true)));
    }

    trackElement($product);

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->setBasePrice($price);
    $variant->setPrimaryOwner($product);
    $variant->setOwner($product);

    if (!craftApp()->getElements()->saveElement($variant)) {
        throw new RuntimeException('Could not save catalog test variant: ' . implode(', ', $variant->getErrorSummary(true)));
    }

    trackElement($variant);

    return $variant;
}

/**
 * A JSON catalog condition matching only products of the given type.
 */
function catalogConditionForType(ProductType $type): string
{
    $condition = new CatalogPricingRuleProductCondition();
    $condition->elementType = Product::class;

    $rule = new ProductTypeConditionRule();
    $rule->setValues([$type->uid]);

    $condition->addConditionRule($rule);

    return Json::encode($condition->getConfig());
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
    return b2bTestSitePath() . '/storage/runtime/mail';
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
 * A test-only Mailer subclass whose file-transport save step can be made to throw for messages
 * addressed to specific recipients, so a test can force a genuine "the reminder mail send failed"
 * outcome deterministically -- without depending on real network/SMTP conditions. The dev site's
 * mailer config always sets useFileTransport = true (see ../../../b2b-dev/config/app.php), so
 * BaseMailer::send() normally calls saveMessage() -- never the Symfony transport -- to write the
 * .eml files mailDir()/mailCount() read; that is the one method that must be intercepted. Everything
 * else (subject/body rendering, from/replyTo/template handling) is inherited unchanged from the
 * real, correctly configured Mailer.
 */
class FailingSaveMailer extends \craft\mail\Mailer
{
    /** @var callable(string[] $toAddresses): bool */
    public $shouldFail;

    /**
     * @param \yii\mail\MessageInterface $message
     */
    protected function saveMessage($message): bool
    {
        $to = $message->getTo();
        $toAddresses = is_array($to) ? array_keys($to) : (array) $to;

        if (($this->shouldFail)($toAddresses)) {
            throw new RuntimeException('Forced test mail failure');
        }

        return parent::saveMessage($message);
    }
}

/**
 * Runs the callback with Craft's mailer swapped for a {@see FailingSaveMailer} that throws for any
 * outgoing message whose "To" addresses satisfy $shouldFail, and otherwise behaves exactly like the
 * real, correctly configured mailer (including actually writing the .eml file, so
 * mailCount()/mailSnapshot() stay meaningful for non-targeted sends). Lets a test force a precise,
 * targeted mail failure -- e.g. "only the reminder to this one recipient fails" -- regardless of the
 * order in which the dev site's (possibly noisy) companies happen to be processed. The real mailer is
 * restored afterwards, even if the callback throws.
 *
 * @param callable(string[] $toAddresses): bool $shouldFail
 */
function withMailerFailingWhen(callable $shouldFail, callable $callback): void
{
    $app = craftApp();
    $original = $app->getMailer();

    $config = App::mailerConfig();
    $config['class'] = FailingSaveMailer::class;
    $config['useFileTransport'] = true;
    $config['shouldFail'] = $shouldFail;

    $app->set('mailer', Craft::createObject($config));

    try {
        $callback();
    } finally {
        $app->set('mailer', $original);
    }
}

/**
 * Runs the callback with Craft's mailer swapped for one whose send() always fails. See
 * {@see withMailerFailingWhen()}.
 */
function withMailerForcedToFail(callable $callback): void
{
    withMailerFailingWhen(fn(): bool => true, $callback);
}

/**
 * Runs the callback with Craft's mailer swapped for one that fails ONLY the send(s) addressed to
 * $email, sending every other recipient normally. Lets a test force one specific reminder in a batch
 * to fail, deterministically, regardless of which order the recipients are otherwise processed in.
 * See {@see withMailerFailingWhen()}.
 */
function withMailerFailingForRecipient(string $email, callable $callback): void
{
    withMailerFailingWhen(
        fn(array $toAddresses): bool => in_array($email, $toAddresses, true),
        $callback,
    );
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
 * Runs the callback while $user is the signed-in identity, restoring the previous
 * identity afterwards. Shared by any test that needs to enforce/read something as a
 * specific active identity (feature toggles, on-behalf no-elevation checks, ...).
 */
function asIdentity(craft\elements\User $user, callable $callback): void
{
    $userComponent = craftApp()->getUser();
    $previous = $userComponent->getIdentity();
    $userComponent->setIdentity($user);

    try {
        $callback();
    } finally {
        $userComponent->setIdentity($previous);
    }
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
 * The email address of a company's first admin member, resolved exactly the way
 * {@see totalwebcreations\b2bcommerce\modules\invoicing\services\Dunning::sendReminder()} resolves
 * its recipients -- so a test can target a mail-transport failure at one specific company's
 * reminder, deterministically, without depending on internally generated test-user email formats.
 */
function companyAdminEmail(Company $company): string
{
    foreach (Plugin::getInstance()->companyMembers->getMemberUsers($company->id) as $row) {
        if ($row['role'] === CompanyRole::Admin && $row['user']->email) {
            return $row['user']->email;
        }
    }

    throw new RuntimeException("No admin email found for company {$company->id}.");
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

/**
 * Test-only impersonation session shim.
 *
 * The Integration suite boots a console craft\console\Application (see bootstrap.php), whose
 * craft\console\User has no notion of impersonation: getImpersonatorId()/setImpersonatorId()/
 * loginByUserId() exist only on craft\web\User, which is backed by a real HTTP session/cookie
 * stack that a console process does not have. Production storefront code always runs on the web
 * app, so SalesReps::actAs()/endActingAs()/resolveActingRepId() — and, at completion,
 * OrderCompanyLink::linkCompany() calling resolveActingRepId() — freely call those three methods
 * on Craft::$app->getUser().
 *
 * To exercise that logic at Integration speed without standing up a full web app (session,
 * response, cookies), attachImpersonationTestShim() attaches — once, at bootstrap, for EVERY
 * test — a tiny behavior reproducing just those three methods' observable contract: a null
 * impersonator by default (exactly like a fresh web session), settable to a rep, plus an
 * identity-switching login. It MUST be attached universally because ordinary order-completion
 * tests trigger linkCompany -> resolveActingRepId -> getImpersonatorId() without ever touching
 * impersonation. Yii's Component::__call() only reaches attached behaviors for method names the
 * component does NOT already define, so this cannot shadow console\User's own
 * getId()/getIdentity()/setIdentity(). It is test-only scaffolding; production code is
 * unmodified. The real web session path (actual cookies, actual login) is covered end-to-end by
 * tests/Http/SalesRepHttpTest.php against the real dev site.
 */
function attachImpersonationTestShim(craft\console\User $userComponent): void
{
    if ($userComponent->getBehavior('impersonationTestShim') !== null) {
        return;
    }

    $userComponent->attachBehavior('impersonationTestShim', new class extends yii\base\Behavior {
        private ?int $impersonatorId = null;

        // Deliberately OMITS the real craft\web\User path's impersonateUsers read-gate: production
        // only surfaces an impersonator id when the impersonator actually holds that permission,
        // whereas this shim returns whatever id was set. That makes the shim STRICTLY MORE
        // PERMISSIVE than production, so it can never hide a privilege escalation — any refusal that
        // still holds here (e.g. resolveActingRepId returning null for a non-rep, or a budget
        // refusal) holds a fortiori on the real, permission-gated web path. The genuine
        // impersonateUsers-gated flow is proven end-to-end in tests/Http/SalesRepHttpTest.php.
        public function getImpersonatorId(): ?int
        {
            return $this->impersonatorId;
        }

        public function setImpersonatorId(?int $id): void
        {
            $this->impersonatorId = $id;
        }

        public function loginByUserId(int $userId, int $duration = 0): bool
        {
            $user = craftApp()->getUsers()->getUserById($userId);

            if ($user === null) {
                return false;
            }

            $this->owner->setIdentity($user);

            return true;
        }
    });
}

/**
 * Returns the shimmed console user component (see attachImpersonationTestShim). Callers that need
 * to drive impersonation state in a test use this; the shim itself is already attached at
 * bootstrap, so this is just an explicit, self-documenting accessor.
 */
function impersonationTestUser(): craft\console\User
{
    $userComponent = craftApp()->getUser();
    attachImpersonationTestShim($userComponent);

    return $userComponent;
}

/**
 * A tracked, approved company that pays on account, with a generous credit limit and the given
 * payment term (drives order.b2bPaymentDueDate = dateOrdered + paymentTermDays).
 */
function statementCompany(int $paymentTermDays): Company
{
    $company = createTestCompany(Company::STATUS_APPROVED);
    $company->allowInvoicePayment = true;
    $company->creditLimit = 100000.0;
    $company->paymentTermDays = $paymentTermDays;

    if (!craftApp()->getElements()->saveElement($company)) {
        throw new RuntimeException('Could not save statement test company: ' . implode(', ', $company->getFirstErrors()));
    }

    return $company;
}

/**
 * Backdates a completed order's dateOrdered by $daysAgo days straight in the table, so its derived
 * b2bPaymentDueDate lands in the past. The statement/dunning services reload the order fresh via
 * getOrderById, so a raw update is enough and sidesteps a full re-save with its recalculation.
 */
function backdateOrder(\craft\commerce\elements\Order $order, int $daysAgo): void
{
    $date = (new DateTimeImmutable("-{$daysAgo} days"))->format('Y-m-d H:i:s');

    craftApp()->getDb()->createCommand()
        ->update('{{%commerce_orders}}', ['dateOrdered' => $date], ['id' => $order->id])
        ->execute();
}
