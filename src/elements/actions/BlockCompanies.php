<?php

namespace totalwebcreations\b2bcommerce\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

class BlockCompanies extends ElementAction
{
    public function getTriggerLabel(): string
    {
        return Craft::t('b2b-commerce', 'Block');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $failures = 0;

        foreach ($query->all() as $company) {
            try {
                Plugin::getInstance()->companyApproval->block($company);
            } catch (InvalidArgumentException) {
                $failures++;
            }
        }

        if ($failures > 0) {
            $this->setMessage(Craft::t('b2b-commerce', '{count} companies could not be blocked.', ['count' => $failures]));

            return false;
        }

        $this->setMessage(Craft::t('b2b-commerce', 'Companies blocked.'));

        return true;
    }
}
