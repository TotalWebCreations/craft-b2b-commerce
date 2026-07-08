<?php

use craft\db\Query;

it('created the multi-level approval tables', function () {
    $tiers = (new Query())->from('{{%b2b_approval_tiers}}')->all();
    $steps = (new Query())->from('{{%b2b_approval_steps}}')->all();

    expect($tiers)->toBeArray()
        ->and($steps)->toBeArray();
});
