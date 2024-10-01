<?php

namespace Paymongo\Phaymongo;

class MagentoOrder extends BaseOrder {
    protected $order;
    protected $billing_address;

    public function __construct($order)
    {
        $this->order = $order;
        $this->billing_address = $order->getBillingAddress();
    }

    public function getFirstName()
    {
        return $this->billing_address->getFirstName();
    }

    public function getLastName()
    {
        return $this->billing_address->getLastName();
    }

    public function getEmail()
    {
        return $this->billing_address->getEmail();
    }

    public function getPhone()
    {
        return $this->billing_address->getTelephone();
    }

    public function getAddress1()
    {
        return $this->billing_address->getStreetLine(1);
    }

    public function getAddress2()
    {
        return $this->billing_address->getStreetLine(2);
    }

    public function getCity()
    {
        return $this->billing_address->getCity();
    }

    public function getState()
    {
        return $this->billing_address->getRegion();
    }

    public function getCountry()
    {
        return $this->billing_address->getCountryId();
    }

    public function getPostalCode()
    {
        return $this->billing_address->getPostcode();
    }
}