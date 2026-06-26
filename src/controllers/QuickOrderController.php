<?php

namespace totalwebcreations\b2bcommerce\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\helpers\FileHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use totalwebcreations\b2bcommerce\controllers\concerns\ReadsStringBodyParams;
use totalwebcreations\b2bcommerce\controllers\concerns\RequiresFeature;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;
use yii\web\Response;

class QuickOrderController extends Controller
{
    use ReadsStringBodyParams;
    use RequiresFeature;

    private const MAX_CSV_BYTES = 1024 * 1024;

    /** @var array<int, string> Extra MIME types accepted besides anything under text/* */
    private const CSV_MIME_TYPES = ['application/csv', 'application/vnd.ms-excel'];

    protected array|bool|int $allowAnonymous = [
        'add' => self::ALLOW_ANONYMOUS_LIVE,
        'upload-csv' => self::ALLOW_ANONYMOUS_LIVE,
    ];

    public function actionAdd(): ?Response
    {
        $this->requirePostRequest();

        if ($response = $this->requireFeature('enableQuickOrder')) {
            return $response;
        }

        if (!$this->canPurchase()) {
            return $this->asFailure(
                Craft::t('b2b-commerce', 'You need an approved business account to order.')
            );
        }

        $input = $this->stringBodyParam('lines');

        return $this->addToCartResponse($input);
    }

    public function actionUploadCsv(): ?Response
    {
        $this->requirePostRequest();

        if ($response = $this->requireFeature('enableQuickOrder')) {
            return $response;
        }

        if (!$this->canPurchase()) {
            return $this->asFailure(
                Craft::t('b2b-commerce', 'You need an approved business account to order.')
            );
        }

        $file = UploadedFile::getInstanceByName('csvFile');

        if ($file === null || $file->getHasError()) {
            return $this->asFailure(Craft::t('b2b-commerce', 'Select a CSV file to upload.'));
        }

        if ($file->size > self::MAX_CSV_BYTES) {
            return $this->asFailure(Craft::t('b2b-commerce', 'The CSV file is too large (max 1 MB).'));
        }

        $mimeType = FileHelper::getMimeType($file->tempName) ?? $file->type;

        if (!str_starts_with((string) $mimeType, 'text/') && !in_array($mimeType, self::CSV_MIME_TYPES, true)) {
            return $this->asFailure(Craft::t('b2b-commerce', 'The uploaded file must be a text or CSV file.'));
        }

        $content = $this->stripBom((string) file_get_contents($file->tempName));

        return $this->addToCartResponse($content);
    }

    public function actionReorder(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        if ($response = $this->requireFeature('enableQuickOrder')) {
            return $response;
        }

        if (!$this->canPurchase()) {
            return $this->asFailure(
                Craft::t('b2b-commerce', 'You need an approved business account to order.')
            );
        }

        $orderId = (int) Craft::$app->getRequest()->getBodyParam('orderId');

        $source = Order::find()->id($orderId)->isCompleted(true)->one();

        if ($source === null) {
            return $this->asFailure(Craft::t('b2b-commerce', 'Order not found.'));
        }

        $cart = Commerce::getInstance()->getCarts()->getCart(true);
        $actor = Craft::$app->getUser()->getIdentity();

        try {
            $result = Plugin::getInstance()->quickOrder->reorder($cart, $source, $actor);
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(
            Craft::t('b2b-commerce', '{count} items added to your cart.', ['count' => $result['added']]),
            $result,
        );
    }

    private function canPurchase(): bool
    {
        return Plugin::getInstance()->priceVisibility->canPurchase(
            Craft::$app->getUser()->getIdentity()
        );
    }

    private function addToCartResponse(string $input): Response
    {
        $cart = Commerce::getInstance()->getCarts()->getCart(true);

        try {
            $result = Plugin::getInstance()->quickOrder->addToCart($cart, $input);
        } catch (InvalidArgumentException $exception) {
            return $this->asFailure($exception->getMessage());
        }

        return $this->asSuccess(
            Craft::t('b2b-commerce', '{count} items added to your cart.', ['count' => $result['added']]),
            $result,
        );
    }

    private function stripBom(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        return $content;
    }
}
