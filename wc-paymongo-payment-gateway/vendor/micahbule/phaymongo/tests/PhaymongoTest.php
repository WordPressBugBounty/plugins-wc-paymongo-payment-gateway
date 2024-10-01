<?php

use Paymongo\Phaymongo\Link;
use Paymongo\Phaymongo\Payment;
use Paymongo\Phaymongo\PaymentIntent;
use Paymongo\Phaymongo\PaymentMethod;
use Paymongo\Phaymongo\Phaymongo;
use Paymongo\Phaymongo\Refund;
use Paymongo\Phaymongo\Source;

beforeEach(function () {
    $this->client = new Phaymongo('a', 'b');
});

it('can instantiate a payment intent resource class', function () {
    expect($this->client->paymentIntent())->toBeInstanceOf(PaymentIntent::class);
});

it('can instantiate a payment method resource class', function () {
    expect($this->client->paymentMethod())->toBeInstanceOf(PaymentMethod::class);
});

it('can instantiate a source resource class', function () {
    expect($this->client->source())->toBeInstanceOf(Source::class);
});

it('can instantiate a payment resource class', function () {
    expect($this->client->payment())->toBeInstanceOf(Payment::class);
});

it('can instantiate a refund resource class', function () {
    expect($this->client->refund())->toBeInstanceOf(Refund::class);
});

it('can instantiate a link resource class', function () {
    expect($this->client->link())->toBeInstanceOf(Link::class);
});