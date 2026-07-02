<?php

use craft\fieldlayoutelements\CustomField;
use craft\fields\PlainText;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use totalwebcreations\b2bcommerce\elements\Company;

beforeEach(function () {
    // This is the only suite test that mutates GLOBAL field state (fields + field layouts) rather
    // than tracked elements, and deleteTrackedElements() -- the suite's sole between-test cleanup --
    // never touches it. A run interrupted between saveLayout() and the finally block (or a killed
    // process) leaves a LIVE Company field layout behind; because Fields::getLayoutByType() returns
    // the FIRST matching layout, that orphan then shadows the layout a test just created and the
    // assertions read the wrong layout -- the intermittent failure this guards against. Hard-purge
    // every Company layout (live or trashed, which also stops soft-deleted rows accumulating) and
    // any leftover throwaway field, then reset the fields cache, so each test starts clean.
    $fields = craftApp()->getFields();

    craftApp()->getDb()->createCommand()
        ->delete('{{%fieldlayouts}}', ['type' => Company::class])
        ->execute();

    foreach ($fields->getAllFields() as $field) {
        if (str_starts_with((string) $field->handle, 'b2bTestNotes')) {
            $fields->deleteField($field);
        }
    }

    // Soft-deletes nothing now, but nulls the memoized layout cache so the next getLayoutByType()
    // re-reads from the freshly purged table rather than a stale in-memory copy.
    $fields->deleteLayoutsByType(Company::class);
});

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
