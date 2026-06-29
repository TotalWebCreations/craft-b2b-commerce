<?php

namespace totalwebcreations\b2bcommerce\modules\quotes\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\enums\QuoteStatus;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\Component;
use yii\base\InvalidArgumentException;

class Quotes extends Component
{
    /**
     * Turns the given cart into a quote request: records a requested quote row for the
     * actor's approved company and notifies an admin, then forgets the session cart so
     * the order survives untouched as the quote while the customer keeps a fresh cart.
     */
    public function requestQuote(Order $cart, User $actor, ?string $notes): void
    {
        if ($cart->getLineItems() === []) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'Your cart is empty.')
            );
        }

        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($actor->id);

        if ($company === null || $company->companyStatus !== Company::STATUS_APPROVED) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'Only approved company members can request quotes.')
            );
        }

        if ($this->orderIsQuote((int) $cart->id)) {
            throw new InvalidArgumentException(
                Craft::t('b2b-commerce', 'This cart is already a quote request.')
            );
        }

        Db::insert('{{%b2b_quotes}}', [
            'orderId' => $cart->id,
            'companyId' => $company->id,
            'status' => QuoteStatus::Requested->value,
            'notes' => $notes,
            'requestedById' => $actor->id,
            'acceptToken' => StringHelper::randomString(40),
        ]);

        $this->notifyAdmin($company, $cart);

        Commerce::getInstance()->getCarts()->forgetCart();
    }

    private function orderIsQuote(int $orderId): bool
    {
        return (new Query())
            ->from('{{%b2b_quotes}}')
            ->where(['orderId' => $orderId])
            ->exists();
    }

    private function notifyAdmin(Company $company, Order $cart): void
    {
        $to = Plugin::getInstance()->getSettings()->adminNotificationEmail
            ?: App::parseEnv(App::mailSettings()->fromEmail);

        if (!$to) {
            return;
        }

        $sent = Craft::$app->getMailer()
            ->compose()
            ->setTo($to)
            ->setSubject(Craft::t('b2b-commerce', 'New quote request: {company}', ['company' => $company->title]))
            ->setTextBody(Craft::t('b2b-commerce', '{company} requested a quote. Review it in the control panel: {url}', [
                'company' => $company->title,
                'url' => $cart->getCpEditUrl(),
            ]))
            ->send();

        if (!$sent) {
            Craft::warning("Failed to send quote request notification to {$to}", 'b2b-commerce');
        }
    }
}
