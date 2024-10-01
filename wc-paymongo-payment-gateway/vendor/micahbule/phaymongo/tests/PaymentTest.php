<?php

/**
 * @runInSeparateProcess
 */

it('can create a payment without optional fields', function () {
    $dummyAttributes = array(
        'amount' => 10000,
        'currency' => 'PHP',
        'source' => array(
            'id' => 1,
            'type' => 'source'
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

    $paymentMock = \Mockery::mock('Paymongo\Phaymongo\Payment')->makePartial();
    $paymentMock->shouldReceive('createResource')
        ->withArgs([$dummyPayload])
        ->atLeast()
        ->times(1)
        ->andReturn(['success' => true]);
    
    $response = $paymentMock->create(100, 1);

    expect($response)->toBe(['success' => true]);
});

it('can create a payment with optional fields', function () {
    $dummyAttributes = array(
        'amount' => 10000,
        'currency' => 'PHP',
        'source' => array(
            'id' => 1,
            'type' => 'source'
        ),
        'description' => 'Sample description',
        'statement_descriptor' => 'Some Bank',
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

    $paymentMock = \Mockery::mock('Paymongo\Phaymongo\Payment')->makePartial();
    $paymentMock->shouldReceive('createResource')
        ->withArgs([$dummyPayload])
        ->atLeast()
        ->times(1)
        ->andReturn(['success' => true]);
    
    $response = $paymentMock->create(100, 1, 'Sample description', 'Some Bank', ['some' => 'data']);

    expect($response)->toBe(['success' => true]);
});

it('can retrieve a payment by ID', function () {
    $paymentMock = \Mockery::mock('Paymongo\Phaymongo\Payment')->makePartial();
    $paymentMock->shouldReceive('retrieveResourceById')
        ->withArgs([1])
        ->atLeast()
        ->times(1);
    
    $paymentMock->retrieveById(1);
});

it('can retrieve all payments', function () {
    $paymentMock = \Mockery::mock('Paymongo\Phaymongo\Payment')->makePartial();
    $paymentMock->shouldReceive('createRequest')
        ->withArgs(['GET', '/payments'])
        ->atLeast()
        ->times(1)
        ->andReturn(true);

    $paymentMock->shouldReceive('sendRequest')
        ->withArgs([true, ['query' => ['before' => 50, 'limit' => 20]]])
        ->atLeast()
        ->times(1);
    
    $paymentMock->retrieveAll(50, null, 20);
});
