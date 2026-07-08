<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use totalwebcreations\b2bcommerce\controllers\concerns\ReadsStringBodyParams;
use totalwebcreations\b2bcommerce\controllers\concerns\RequiresFeature;
use totalwebcreations\b2bcommerce\Plugin;
use yii\web\Response;

class CheckoutController extends Controller
{
    use ReadsStringBodyParams;
    use RequiresFeature;

    /**
     * Writes the buyer's purchase-order / reference number onto the current cart. Member-guarded:
     * requireLogin plus a company-membership check, so a signed-in shopper with no company cannot
     * stamp a PO. CSRF is enforced by Craft on every POST. The PO rides under enableCompanies.
     */
    public function actionSetReference(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        if ($response = $this->requireFeature('enableCompanies')) {
            return $response;
        }

        $user = Craft::$app->getUser()->getIdentity();
        $company = Plugin::getInstance()->companyMembers->getCompanyForUser($user->id);

        if ($company === null) {
            return $this->asFailure(
                Craft::t('b2b-commerce', 'You need to belong to a company to set a purchase order number.')
            );
        }

        $cart = Commerce::getInstance()->getCarts()->getCart(true);
        $poNumber = $this->stringBodyParam('poNumber');

        Plugin::getInstance()->orderReferences->setPoNumber($cart, $poNumber !== '' ? $poNumber : null);

        return $this->asSuccess(
            Craft::t('b2b-commerce', 'Your purchase order number has been saved.'),
            ['poNumber' => $cart->b2bPoNumber]
        );
    }
}
