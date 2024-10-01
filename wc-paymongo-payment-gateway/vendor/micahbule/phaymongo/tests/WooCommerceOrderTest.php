<?php

use Paymongo\Phaymongo\WooCommerceOrder;

beforeEach(function () {
    $mock = \Mockery::mock();
    $mock->shouldReceive([
        'get_billing_first_name' => 'John',
        'get_billing_last_name' => 'Doe',
        'get_billing_email' => 'john.doe@example.com',
        'get_billing_phone' => '123456',
        'get_billing_address_1' => '1',
        'get_billing_address_2' => '2',
        'get_billing_city' => '3',
        'get_billing_state' => '4',
        'get_billing_country' => '5',
        'get_billing_postcode' => '6',
    ]);

    $this->wcOrder = new WooCommerceOrder($mock);
});

it('can get the first name', function () {
    expect($this->wcOrder->getFirstName())->toBe('John');
});

it('can get the last name', function () {
    expect($this->wcOrder->getLastName())->toBe('Doe');
});

it('can get the email', function () {
    expect($this->wcOrder->getEmail())->toBe('john.doe@example.com');
});

it('can get the phone', function () {
    expect($this->wcOrder->getPhone())->toBe('123456');
});

it('can get the address line 1', function () {
    expect($this->wcOrder->getAddress1())->toBe('1');
});

it('can get the address line 2', function () {
    expect($this->wcOrder->getAddress2())->toBe('2');
});

it('can get the city', function () {
    expect($this->wcOrder->getCity())->toBe('3');
});

it('can get the state', function () {
    expect($this->wcOrder->getState())->toBe('4');
});

it('can get the country', function () {
    expect($this->wcOrder->getCountry())->toBe('5');
});

it('can get the postal code', function () {
    expect($this->wcOrder->getPostalCode())->toBe('6');
});