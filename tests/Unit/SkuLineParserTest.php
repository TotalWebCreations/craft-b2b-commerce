<?php

use totalwebcreations\b2bcommerce\modules\quickorder\parsers\SkuLineParser;

beforeEach(function () {
    $this->parser = new SkuLineParser();
});

it('parses a tab-delimited line (Excel paste)', function () {
    $result = $this->parser->parse("SKU-1\t5");

    expect($result['lines'])->toBe([1 => ['sku' => 'SKU-1', 'qty' => 5]])
        ->and($result['errors'])->toBe([]);
});

it('parses a comma-delimited line', function () {
    $result = $this->parser->parse('SKU-1,5');

    expect($result['lines'])->toBe([1 => ['sku' => 'SKU-1', 'qty' => 5]]);
});

it('parses a semicolon-delimited line', function () {
    $result = $this->parser->parse('SKU-1;5');

    expect($result['lines'])->toBe([1 => ['sku' => 'SKU-1', 'qty' => 5]]);
});

it('parses a whitespace-delimited line', function () {
    $result = $this->parser->parse('SKU-1   5');

    expect($result['lines'])->toBe([1 => ['sku' => 'SKU-1', 'qty' => 5]]);
});

it('defaults a bare SKU to quantity 1', function () {
    $result = $this->parser->parse('SKU-1');

    expect($result['lines'])->toBe([1 => ['sku' => 'SKU-1', 'qty' => 1]]);
});

it('sums duplicate SKUs case-insensitively keeping the first casing and line number', function () {
    $result = $this->parser->parse("abc,2\nABC,3");

    expect($result['lines'])->toBe([1 => ['sku' => 'abc', 'qty' => 5]])
        ->and($result['errors'])->toBe([]);
});

it('handles CRLF line endings', function () {
    $result = $this->parser->parse("SKU-1,2\r\nSKU-2,3");

    expect($result['lines'])->toBe([
        1 => ['sku' => 'SKU-1', 'qty' => 2],
        2 => ['sku' => 'SKU-2', 'qty' => 3],
    ]);
});

it('handles lone CR line endings', function () {
    $result = $this->parser->parse("SKU-1,2\rSKU-2,3");

    expect($result['lines'])->toBe([
        1 => ['sku' => 'SKU-1', 'qty' => 2],
        2 => ['sku' => 'SKU-2', 'qty' => 3],
    ]);
});

it('skips empty and whitespace-only lines while preserving original line numbers', function () {
    $result = $this->parser->parse("abc,2\n\n   \ndef,3");

    expect($result['lines'])->toBe([
        1 => ['sku' => 'abc', 'qty' => 2],
        4 => ['sku' => 'def', 'qty' => 3],
    ])->and($result['errors'])->toBe([]);
});

it('rejects a zero quantity', function () {
    $result = $this->parser->parse('SKU-1,0');

    expect($result['lines'])->toBe([])
        ->and($result['errors'])->toBe([1 => 'Invalid quantity']);
});

it('rejects a negative quantity', function () {
    $result = $this->parser->parse('SKU-1,-1');

    expect($result['errors'])->toBe([1 => 'Invalid quantity']);
});

it('rejects a fractional quantity', function () {
    $result = $this->parser->parse('SKU-1,2.5');

    expect($result['errors'])->toBe([1 => 'Invalid quantity']);
});

it('rejects a non-numeric quantity', function () {
    $result = $this->parser->parse('SKU-1,abc');

    expect($result['errors'])->toBe([1 => 'Invalid quantity']);
});

it('does not support thousands separators', function () {
    $result = $this->parser->parse("SKU-1\t1,000");

    expect($result['lines'])->toBe([])
        ->and($result['errors'])->toBe([1 => 'Invalid quantity']);
});

it('reports a missing SKU when the SKU part is empty after splitting', function () {
    $result = $this->parser->parse(',3');

    expect($result['lines'])->toBe([])
        ->and($result['errors'])->toBe([1 => 'Missing SKU']);
});

it('trims surrounding whitespace around the SKU and quantity', function () {
    $result = $this->parser->parse('  SKU-1 , 5  ');

    expect($result['lines'])->toBe([1 => ['sku' => 'SKU-1', 'qty' => 5]]);
});

it('parses mixed delimiters across lines', function () {
    $result = $this->parser->parse("SKU-1\t2\nSKU-2,3\nSKU-3;4\nSKU-4 5\nSKU-5");

    expect($result['lines'])->toBe([
        1 => ['sku' => 'SKU-1', 'qty' => 2],
        2 => ['sku' => 'SKU-2', 'qty' => 3],
        3 => ['sku' => 'SKU-3', 'qty' => 4],
        4 => ['sku' => 'SKU-4', 'qty' => 5],
        5 => ['sku' => 'SKU-5', 'qty' => 1],
    ]);
});

it('keeps valid lines and collects errors from invalid ones with their own line numbers', function () {
    $result = $this->parser->parse("SKU-1,2\nSKU-2,0\nSKU-3");

    expect($result['lines'])->toBe([
        1 => ['sku' => 'SKU-1', 'qty' => 2],
        3 => ['sku' => 'SKU-3', 'qty' => 1],
    ])->and($result['errors'])->toBe([2 => 'Invalid quantity']);
});
