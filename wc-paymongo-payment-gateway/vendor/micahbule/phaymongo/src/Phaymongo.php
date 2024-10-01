<?php

namespace Paymongo\Phaymongo;

use Paymongo\Phaymongo\Link;
use Paymongo\Phaymongo\Payment;
use Paymongo\Phaymongo\PaymentIntent;
use Paymongo\Phaymongo\PaymentMethod;
use Paymongo\Phaymongo\Refund;
use Paymongo\Phaymongo\Source;

class Phaymongo {
    protected $public_key;
    protected $secret_key;
    protected $client_ops;
    protected $guzzle_ops;

    public function __construct($public_key, $secret_key, $client_ops = [], $guzzle_ops = [])
    {
        $this->public_key = $public_key;
        $this->secret_key = $secret_key;
        $this->client_ops = $client_ops;
        $this->guzzle_ops = $guzzle_ops;
    }

    public function paymentIntent() {
        return new PaymentIntent($this->public_key, $this->secret_key, $this->client_ops, $this->guzzle_ops);
    }

    public function paymentMethod() {
        return new PaymentMethod($this->public_key, $this->secret_key, $this->client_ops, $this->guzzle_ops);
    }

    public function payment() {
        return new Payment($this->public_key, $this->secret_key, $this->client_ops, $this->guzzle_ops);
    }

    public function source() {
        return new Source($this->public_key, $this->secret_key, $this->client_ops, $this->guzzle_ops);
    }

    public function refund() {
        return new Refund($this->public_key, $this->secret_key, $this->client_ops, $this->guzzle_ops);
    }

    public function link() {
        return new Link($this->public_key, $this->secret_key, $this->client_ops, $this->guzzle_ops);
    }
}