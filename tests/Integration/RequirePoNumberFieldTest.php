<?php

use totalwebcreations\b2bcommerce\elements\Company;
use totalwebcreations\b2bcommerce\fieldlayoutelements\RequirePoNumberField;

it('includes the requirePoNumber native field on the Company layout', function () {
    craftApp()->getFields()->deleteLayoutsByType(Company::class);

    $layout = craftApp()->getFields()->getLayoutByType(Company::class);

    $found = false;

    foreach ($layout->getAvailableNativeFields() as $field) {
        if ($field instanceof RequirePoNumberField) {
            $found = true;
        }
    }

    expect($found)->toBeTrue();
});

it('renders the requirePoNumber lightswitch reflecting the element value', function () {
    $company = createTestCompany('approved');
    $company->requirePoNumber = true;

    $field = new RequirePoNumberField();

    expect($field->inputHtml($company))->toContain('lightswitch');
});
