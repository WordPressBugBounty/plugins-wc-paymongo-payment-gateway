<?php

use Paymongo\Phaymongo\MagentoOrder;
use Paymongo\Phaymongo\PaymongoUtils;
use Paymongo\Phaymongo\WooCommerceOrder;

it('can check if billing value is set', function () {
    expect(PaymongoUtils::is_billing_value_set('apple'))->toBeTrue();
    expect(PaymongoUtils::is_billing_value_set(''))->toBeFalsy();
});

it('can get the order class', function () {
    $mock = \Mockery::mock();
    $mock->shouldReceive('getBillingAddress')->andReturn('a');

    expect(PaymongoUtils::getOrderClass(\Mockery::mock(), 'woocommerce'))->toBeInstanceOf(WooCommerceOrder::class);
    expect(PaymongoUtils::getOrderClass($mock, 'magento'))->toBeInstanceOf(MagentoOrder::class);
});

it('can throw an error if invalid order class type', function () {
    PaymongoUtils::getOrderClass(\Mockery::mock(), 'prestashop');
})->throws('Invalid order class type');

it('can construct Paymongo payload', function () {
    expect(PaymongoUtils::constructPayload(['foo' => 'bar']))->toBe(['data' => ['attributes' => ['foo' => 'bar']]]);
});

it('can generate billing object for woocommerce order', function () {
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

    expect(PaymongoUtils::generateBillingObject($mock, 'woocommerce'))->toBe([
        'name' => 'John Doe',
        'email' => 'john.doe@example.com',
        'phone' => '123456',
        'address' => [
            'line1' => '1',
            'line2' => '2',
            'city' => '3',
            'state' => '4',
            'country' => '5',
            'postal_code' => '6',
        ]
    ]);
});

it('can generate a billing object for magento order', function () {
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

    expect(PaymongoUtils::generateBillingObject($mock, 'magento'))->toBe([
        'name' => 'John Doe',
        'email' => 'john.doe@example.com',
        'phone' => '123456',
        'address' => [
            'line1' => '1',
            'line2' => '2',
            'city' => '3',
            'state' => '4',
            'country' => '5',
            'postal_code' => '6',
        ]
    ]);
});
