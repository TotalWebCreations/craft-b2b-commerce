<?php

use totalwebcreations\b2bcommerce\Plugin;

function orderReferences(): \totalwebcreations\b2bcommerce\modules\checkout\services\OrderReferences
{
    return Plugin::getInstance()->orderReferences;
}

it('returns null when no PO has been set', function () {
    $order = createTestOrder(null);

    expect(orderReferences()->getPoNumber($order->id))->toBeNull();
});

it('sets and reads a PO number on an order', function () {
    $order = createTestOrder(null);

    orderReferences()->setPoNumber($order, 'PO-1001');

    expect(orderReferences()->getPoNumber($order->id))->toBe('PO-1001');
});

it('overwrites an existing PO number', function () {
    $order = createTestOrder(null);

    orderReferences()->setPoNumber($order, 'PO-1001');
    orderReferences()->setPoNumber($order, 'PO-2002');

    expect(orderReferences()->getPoNumber($order->id))->toBe('PO-2002');
});

it('trims whitespace and treats a blank PO as null', function () {
    $order = createTestOrder(null);

    orderReferences()->setPoNumber($order, '  PO-3003  ');
    expect(orderReferences()->getPoNumber($order->id))->toBe('PO-3003');

    orderReferences()->setPoNumber($order, '   ');
    expect(orderReferences()->getPoNumber($order->id))->toBeNull();
});
