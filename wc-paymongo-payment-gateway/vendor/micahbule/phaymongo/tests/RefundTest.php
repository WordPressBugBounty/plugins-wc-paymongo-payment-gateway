<?php

it('can create a refund without optional fields', function () {
    $dummyAttributes = array(
        'amount' => 10000,
        'payment_id' => 1,
        'reason' => 'fraudulent',
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

    $refundMock = \Mockery::mock('Paymongo\Phaymongo\Refund')->makePartial();
    $refundMock->shouldReceive('createResource')
        ->withArgs([$dummyPayload])
        ->atLeast()
        ->times(1)
        ->andReturn(['success' => true]);

    $response = $refundMock->create(100, 1, 'fraudulent');

    expect($response)->toBe(['success' => true]);
});

it('can create a refund with optional fields', function () {
    $dummyAttributes = array(
        'amount' => 10000,
        'payment_id' => 1,
        'reason' => 'fraudulent',
        'notes' => 'Some notes',
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

    $refundMock = \Mockery::mock('Paymongo\Phaymongo\Refund')->makePartial();
    $refundMock->shouldReceive('createResource')
        ->withArgs([$dummyPayload])
        ->atLeast()
        ->times(1)
        ->andReturn(['success' => true]);

    $response = $refundMock->create(100, 1, 'fraudulent', 'Some notes', ['some' => 'data']);

    expect($response)->toBe(['success' => true]);
});

it('can retrieve a refund by ID', function () {
    $refundMock = \Mockery::mock('Paymongo\Phaymongo\Refund')->makePartial();
    $refundMock->shouldReceive('retrieveResourceById')
        ->withArgs([1])
        ->atLeast()
        ->times(1);
    
    $refundMock->retrieveById(1);
});