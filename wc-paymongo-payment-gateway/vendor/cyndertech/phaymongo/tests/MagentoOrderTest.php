<?php

use Paymongo\Phaymongo\MagentoOrder;

beforeEach(function () {
    $billingMock = \Mockery::mock();
    $billingMock->shouldReceive([
        'getFirstName' => 'John',
        'getLastName' => 'Doe',
        'getEmail' => 'john.doe@example.com',
        'getTelephone' => '123456',
        'getCity' => '3',
        'getRegion' => '4',
        'getCountryId' => '5',
        'getPostcode' => '6',
    ]);
    $billingMock->shouldReceive('getStreetLine')->with('1')->andReturn('1');
    $billingMock->shouldReceive('getStreetLine')->with('2')->andReturn('2');

    $mock = \Mockery::mock();
    $mock->shouldReceive('getBillingAddress')->andReturn($billingMock);

    $this->mgOrder = new MagentoOrder($mock);
});

it('can get the first name', function () {
    expect($this->mgOrder->getFirstName())->toBe('John');
});

it('can get the last name', function () {
    expect($this->mgOrder->getLastName())->toBe('Doe');
});

it('can get the email', function () {
    expect($this->mgOrder->getEmail())->toBe('john.doe@example.com');
});

it('can get the phone', function () {
    expect($this->mgOrder->getPhone())->toBe('123456');
});

it('can get the address line 1', function () {
    expect($this->mgOrder->getAddress1())->toBe('1');
});

it('can get the address line 2', function () {
    expect($this->mgOrder->getAddress2())->toBe('2');
});

it('can get the city', function () {
    expect($this->mgOrder->getCity())->toBe('3');
});

it('can get the state', function () {
    expect($this->mgOrder->getState())->toBe('4');
});

it('can get the country', function () {
    expect($this->mgOrder->getCountry())->toBe('5');
});

it('can get the postal code', function () {
    expect($this->mgOrder->getPostalCode())->toBe('6');
});