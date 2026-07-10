<?php

namespace totalwebcreations\b2bcommerce\gql\mutations;

use craft\gql\base\Mutation;
use totalwebcreations\b2bcommerce\gql\helpers\Gql as GqlHelper;

/**
 * Checkout write mutations (buyer PO number). Registered only when the active schema has the opt-in
 * `b2bContext.write` component; otherwise no field is added to the Mutation type. Each field is a thin
 * wrapper over the phase-15 checkout service — no business logic lives here.
 */
class Checkout extends Mutation
{
    public static function getMutations(): array
    {
        if (!GqlHelper::canWriteB2bContext()) {
            return [];
        }

        return [];
    }
}
