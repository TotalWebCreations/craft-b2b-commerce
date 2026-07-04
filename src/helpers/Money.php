<?php

namespace totalwebcreations\b2bcommerce\helpers;

/**
 * Fixed-scale money comparisons shared by the credit-limit and spending-budget gates.
 */
final class Money
{
    /**
     * Whether $projected does not exceed $limit, compared as fixed-scale decimal strings.
     *
     * Money totals are summed as floats, so a charge that lands exactly on the limit can miss by a
     * rounding hair (e.g. 49.999999 vs 50) and tip into a false refusal. Comparing as fixed-scale
     * decimal strings removes that float artefact: bccomp works on the exact digits, not an IEEE
     * approximation. Scale 4 is two places beyond currency precision, so a value that is genuinely at
     * (or under) the limit stays allowed while real overruns are still caught. bccomp returns <= 0
     * when $projected does not exceed $limit, which keeps "exactly at the limit" allowed.
     */
    public static function withinLimit(float $projected, float $limit): bool
    {
        return bccomp(
            number_format($projected, 4, '.', ''),
            number_format($limit, 4, '.', ''),
            4
        ) <= 0;
    }
}
