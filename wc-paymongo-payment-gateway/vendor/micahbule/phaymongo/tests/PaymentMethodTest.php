<?php

it('can create a payment method without optional fields', function () {
    $dummyAttributes = array(
        'type' => 'gcash',
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

    $paymentMethodMock = \Mockery::mock('Paymongo\Phaymongo\PaymentMethod')->makePartial();
    $paymentMethodMock->shouldReceive('createResource')
        ->withArgs([$dummyPayload])
        ->atLeast()
        ->times(1)
        ->andReturn(['success' => true]);

    $response = $paymentMethodMock->create('gcash');

    expect($response)->toBe(['success' => true]);
});

it('can create a payment method with optional fields', function () {
    $dummyAttributes = array(
        'type' => 'gcash',
        'details' => ['some' => 'details'],
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

    $paymentMethodMock = \Mockery::mock('Paymongo\Phaymongo\PaymentMethod')->makePartial();
    $paymentMethodMock->shouldReceive('createResource')
        ->withArgs([$dummyPayload])
        ->atLeast()
        ->times(1)
        ->andReturn(['success' => true]);

    $response = $paymentMethodMock->create('gcash', ['some' => 'details'], ['some' => 'billing'], ['some' => 'data']);

    expect($response)->toBe(['success' => true]);
});

it('can retrieve a payment method by ID', function () {
    $paymentMethodMock = \Mockery::mock('Paymongo\Phaymongo\PaymentMethod')->makePartial();
    $paymentMethodMock->shouldReceive('retrieveResourceById')
        ->withArgs([1])
        ->atLeast()
        ->times(1);
    
    $paymentMethodMock->retrieveById(1);
});
