<?php

namespace totalwebcreations\b2bcommerce\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use totalwebcreations\b2bcommerce\Plugin;
use yii\base\InvalidArgumentException;

class ApproveCompanies extends ElementAction
{
    public function getTriggerLabel(): string
    {
        return Craft::t('b2b-commerce', 'Approve');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $failures = 0;

        foreach ($query->all() as $company) {
            try {
                Plugin::getInstance()->companyApproval->approve($company);
            } catch (InvalidArgumentException) {
                $failures++;
            }
        }

        if ($failures > 0) {
            $this->setMessage(Craft::t('b2b-commerce', '{count} companies could not be approved.', ['count' => $failures]));

            return false;
        }

        $this->setMessage(Craft::t('b2b-commerce', 'Companies approved.'));

        return true;
    }
}
