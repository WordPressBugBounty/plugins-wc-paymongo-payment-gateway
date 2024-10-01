<?php

/**
 * @runInSeparateProcess
 */

it('can create a link without remarks', function () {
    $amount = 100;
    $description = 'Sample description';
    
    $dummyAttributes = array(
        'amount' => $amount,
        'description' => $description,
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

    $linkMock = \Mockery::mock('Paymongo\Phaymongo\Link')->makePartial();
    $linkMock->shouldReceive('createResource')
        ->withArgs([$dummyPayload])
        ->atLeast()
        ->times(1)
        ->andReturn(['success' => true]);

    $response = $linkMock->create($amount, $description);

    expect($response)->toBe(['success' => true]);
});

it('can create a link with remarks', function () {
    $amount = 100;
    $description = 'Sample description';
    $remarks = 'Sample remarks';
    
    $dummyAttributes = array(
        'amount' => $amount,
        'description' => $description,
        'remarks' => $remarks,
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

    $linkMock = \Mockery::mock('Paymongo\Phaymongo\Link')->makePartial();
    $linkMock->shouldReceive('createResource')
        ->withArgs([$dummyPayload])
        ->atLeast()
        ->times(1)
        ->andReturn(['success' => true]);

    $response = $linkMock->create($amount, $description, $remarks);

    expect($response)->toBe(['success' => true]);
});

it('can retrieve a link by ID', function () {
    $linkMock = \Mockery::mock('Paymongo\Phaymongo\Link')->makePartial();
    $linkMock->shouldReceive('retrieveResourceById')
        ->withArgs([1])
        ->atLeast()
        ->times(1);
    
    $linkMock->retrieveById(1);
});

it('can retrieve a link by reference number', function () {
    $linkMock = \Mockery::mock('Paymongo\Phaymongo\Link')->makePartial();
    $linkMock->shouldReceive('createRequest')
        ->withArgs(['GET', '/links'])
        ->atLeast()
        ->times(1)
        ->andReturn(true);

    $linkMock->shouldReceive('sendRequest')
        ->withArgs([true, ['query' => ['reference_number' => 1]]])
        ->atLeast()
        ->times(1);
    
    $linkMock->retrieveByReferenceNumber(1);
});

it('can archive a link', function () {
    $linkMock = \Mockery::mock('Paymongo\Phaymongo\Link')->makePartial();
    $linkMock->shouldReceive('createRequest')
        ->withArgs(['POST', '/links/1/archive'])
        ->atLeast()
        ->times(1)
        ->andReturn(true);

    $linkMock->shouldReceive('sendRequest')
        ->withArgs([true])
        ->atLeast()
        ->times(1);
    
    $linkMock->archive(1);
});

it('can unarchive a link', function () {
    $linkMock = \Mockery::mock('Paymongo\Phaymongo\Link')->makePartial();
    $linkMock->shouldReceive('createRequest')
        ->withArgs(['POST', '/links/1/unarchive'])
        ->atLeast()
        ->times(1)
        ->andReturn(true);

    $linkMock->shouldReceive('sendRequest')
        ->withArgs([true])
        ->atLeast()
        ->times(1);
    
    $linkMock->unarchive(1);
});