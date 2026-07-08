<?php

use totalwebcreations\b2bcommerce\Plugin;

it('exposes the PO number through order.b2bPoNumber', function () {
    $order = createTestOrder(null);

    expect($order->b2bPoNumber)->toBeNull();

    Plugin::getInstance()->orderReferences->setPoNumber($order, 'PO-4004');

    expect($order->b2bPoNumber)->toBe('PO-4004');
});

it('returns null for an unsaved order without an id', function () {
    $order = new \craft\commerce\elements\Order();

    expect($order->b2bPoNumber)->toBeNull();
});
