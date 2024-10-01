<?php

/**
 * @runInSeparateProcess
 */

it('can create a payment intent without optional fields', function () {
    $dummyAttributes = array(
        'amount' => 10000,
        'payment_method_allowed' => ['gcash', 'card'],
        'currency' => 'PHP',
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

    $paymentIntentMock = \Mockery::mock('Paymongo\Phaymongo\PaymentIntent')->makePartial();
    $paymentIntentMock->shouldReceive('createResource')
        ->withArgs([$dummyPayload])
        ->atLeast()
        ->times(1)
        ->andReturn(['success' => true]);

    $response = $paymentIntentMock->create(100, ['gcash', 'card']);

    expect($response)->toBe(['success' => true]);
});

it('can create a payment intent with optional fields', function () {
    $dummyAttributes = array(
        'amount' => 10000,
        'payment_method_allowed' => ['gcash', 'card'],
        'payment_method_options' =>  array(
            "card" => array(
                "request_three_d_secure" => "any",
                "installments" => array(
                    "enabled" => true,
                )
            )
        ),
        'currency' => 'PHP',
        'description' => 'Sample description',
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

    $paymentIntentMock = \Mockery::mock('Paymongo\Phaymongo\PaymentIntent')->makePartial();
    $paymentIntentMock->shouldReceive('createResource')
        ->withArgs([$dummyPayload])
        ->atLeast()
        ->times(1)
        ->andReturn(['success' => true]);

    $response = $paymentIntentMock->create(100, ['gcash', 'card'], array(
        "card" => array(
            "request_three_d_secure" => "any",
            "installments" => array(
                "enabled" => true,
            )
        )
    ), 'Sample description', ['some' => 'data']);

    expect($response)->toBe(['success' => true]);
});

it('can retrieve a payment intent by ID', function () {
    $paymentIntentMock = \Mockery::mock('Paymongo\Phaymongo\PaymentIntent')->makePartial();
    $paymentIntentMock->shouldReceive('retrieveResourceById')
        ->withArgs([1])
        ->atLeast()
        ->times(1);
    
    $paymentIntentMock->retrieveById(1);
});

it('can attach a payment method without optional fields', function () {
    $dummyAttributes = array(
        'payment_method' => 1,
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

    $paymentIntentMock = \Mockery::mock('Paymongo\Phaymongo\PaymentIntent')->makePartial();
    $paymentIntentMock->shouldReceive('createRequest')
        ->withArgs(['POST', '/payment_intents/8/attach', $dummyPayload])
        ->atLeast()
        ->times(1)
        ->andReturn(true);
    
    $paymentIntentMock->shouldReceive('sendRequest')
        ->withArgs([true])
        ->atLeast()
        ->times(1)
        ->andReturn(['success' => true]);
    
    $response = $paymentIntentMock->attachPaymentMethod(8, 1);

    expect($response)->toBe(['success' => true]);
});

it('can attach a payment method with optional fields', function () {
    $dummyAttributes = array(
        'payment_method' => 1,
        'client_key' => 'someKey',
        'return_url' => 'https://some-domain.com',
        'payment_method_options' =>  array(
            "card" => array(
                "installments" => array(
                    "plan" => array(
                        "issuer_id" => "00000000000000",
                        "tenure" => 12,
                    )
                )
            )
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

    $paymentIntentMock = \Mockery::mock('Paymongo\Phaymongo\PaymentIntent')->makePartial();
    $paymentIntentMock->shouldReceive('createRequest')
        ->withArgs(['POST', '/payment_intents/8/attach', $dummyPayload])
        ->atLeast()
        ->times(1)
        ->andReturn(true);
    
    $paymentIntentMock->shouldReceive('sendRequest')
        ->withArgs([true])
        ->atLeast()
        ->times(1)
        ->andReturn(['success' => true]);

    $response = $paymentIntentMock->attachPaymentMethod(8, 1, array(
        "card" => array(
            "installments" => array(
                "plan" => array(
                    "issuer_id" => "00000000000000",
                    "tenure" => 12,
                )
            )
        )
    ), 'https://some-domain.com', 'someKey');

    expect($response)->toBe(['success' => true]);
});
