<?php

it('can create a source without optional fields', function () {
    $dummyAttributes = array(
        'type' => 'gcash',
        'amount' => 10000,
        'currency' => 'PHP',
        'redirect' => array(
            'success' => 'https://some-domain.com/success',
            'failed' => 'https://some-domain.com/failed',
        ),
    );

    $dummyPayload = array(
        'data' => array(
            'attributes' => $dummyAttributes,
        ),
    );

    $utilsMock = \Mockery::mock('alias:Paymongo\Phaymongo\PaymongoUtils');
    $utilsMock->shouldReceive('constructPayload')
        ->withArgs([$dummyAttributes])
        ->atLeast()
        ->times(1)
        ->andReturn($dummyPayload);

    $sourceMock = \Mockery::mock('Paymongo\Phaymongo\Source')->makePartial();
    $sourceMock->shouldReceive('createResource')
        ->withArgs([$dummyPayload])
        ->atLeast()
        ->times(1)
        ->andReturn(['success' => true]);

    $response = $sourceMock->create(100, 'gcash', 'https://some-domain.com/success', 'https://some-domain.com/failed');

    expect($response)->toBe(['success' => true]);
});

it('can create a source with optional fields', function () {
    $dummyAttributes = array(
        'type' => 'gcash',
        'amount' => 10000,
        'currency' => 'PHP',
        'redirect' => array(
            'success' => 'https://some-domain.com/success',
            'failed' => 'https://some-domain.com/failed',
        ),
        'billing' => ['some' => 'billing'],
        'metadata' => ['some' => 'data'],
    );

    $dummyPayload = array(
        'data' => array(
            'attributes' => $dummyAttributes,
        ),
    );

    $utilsMock = \Mockery::mock('alias:Paymongo\Phaymongo\PaymongoUtils');
    $utilsMock->shouldReceive('constructPayload')
        ->withArgs([$dummyAttributes])
        ->atLeast()
        ->times(1)
        ->andReturn($dummyPayload);

    $sourceMock = \Mockery::mock('Paymongo\Phaymongo\Source')->makePartial();
    $sourceMock->shouldReceive('createResource')
        ->withArgs([$dummyPayload])
        ->atLeast()
        ->times(1)
        ->andReturn(['success' => true]);

    $response = $sourceMock->create(100, 'gcash', 'https://some-domain.com/success', 'https://some-domain.com/failed', ['some' => 'billing'], ['some' => 'data']);

    expect($response)->toBe(['success' => true]);
});

it('can retrieve a source by ID', function () {
    $sourceMock = \Mockery::mock('Paymongo\Phaymongo\Source')->makePartial();
    $sourceMock->shouldReceive('retrieveResourceById')
        ->withArgs([1])
        ->atLeast()
        ->times(1);
    
    $sourceMock->retrieveById(1);
});