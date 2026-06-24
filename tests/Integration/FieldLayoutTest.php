<?php

use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use totalwebcreations\b2bcommerce\elements\Company;

it('persists a custom field value on a company through the configured layout', function () {
    $fieldsService = craftApp()->getFields();

    $handle = 'b2bTestNotes' . random_int(1000, 9999);

    $field = new PlainText();
    $field->name = 'B2B Test Notes';
    $field->handle = $handle;

    if (!$fieldsService->saveField($field)) {
        throw new RuntimeException('Could not save test field: ' . implode(', ', $field->getFirstErrors()));
    }

    try {
        $layout = new FieldLayout(['type' => Company::class]);
        $tab = new FieldLayoutTab(['name' => 'Content']);
        $tab->setLayout($layout);
        $tab->setElements([new CustomField($field)]);
        $layout->setTabs([$tab]);

        if (!$fieldsService->saveLayout($layout)) {
            throw new RuntimeException('Could not save layout: ' . implode(', ', $layout->getFirstErrors()));
        }

        $company = createTestCompany('approved');
        $company->setFieldValue($handle, 'Handle with care');

        if (!craftApp()->getElements()->saveElement($company)) {
            throw new RuntimeException('Could not save company: ' . implode(', ', $company->getFirstErrors()));
        }

        $requeried = Company::find()->id($company->id)->status(null)->one();

        expect($requeried)->not->toBeNull()
            ->and($requeried->getFieldValue($handle))->toBe('Handle with care')
            ->and($requeried->getFieldLayout()->getFieldByHandle($handle))->not->toBeNull();
    } finally {
        $fieldsService->deleteLayoutsByType(Company::class);
        $fieldsService->deleteField($field);
    }
});

it('falls back to an empty layout when none is configured', function () {
    craftApp()->getFields()->deleteLayoutsByType(Company::class);

    $company = createTestCompany('approved');

    $layout = $company->getFieldLayout();

    expect($layout)->toBeInstanceOf(FieldLayout::class)
        ->and($layout->type)->toBe(Company::class)
        ->and($layout->getCustomFields())->toBe([]);
});
