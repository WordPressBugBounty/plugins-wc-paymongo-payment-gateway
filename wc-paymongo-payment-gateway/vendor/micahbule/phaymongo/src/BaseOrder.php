<?php

namespace Paymongo\Phaymongo;

abstract class BaseOrder {
    protected $order;

    abstract public function __construct($order);

    abstract public function getFirstName();

    abstract public function getLastName();

    abstract public function getEmail();

    abstract public function getPhone();

    abstract public function getAddress1();

    abstract public function getAddress2();

    abstract public function getCity();

    abstract public function getState();

    abstract public function getCountry();

    abstract public function getPostalCode();
}