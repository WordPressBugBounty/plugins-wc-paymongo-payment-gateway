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

it('masks keys in debug output and lists only guzzle option names', function () {
    $client = new \Paymongo\Phaymongo\Phaymongo('pk_live_public', 'sk_live_secret', ['unwrap' => false], ['proxy' => 'http://user:pass@proxy.test']);

    ob_start();
    var_dump($client);
    print_r($client);
    $dump = ob_get_clean();

    expect($dump)
        ->not->toContain('sk_live_secret')
        ->not->toContain('pk_live_public')
        ->not->toContain('user:pass');
});
