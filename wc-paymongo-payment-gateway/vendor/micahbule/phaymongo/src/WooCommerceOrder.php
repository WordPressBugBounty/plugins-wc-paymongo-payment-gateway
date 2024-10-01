<?php

namespace Paymongo\Phaymongo;

class WooCommerceOrder extends BaseOrder {
    protected $order;    

    public function __construct($order)
    {
        $this->order = $order;
    }

    public function getFirstName()
    {
        return $this->order->get_billing_first_name();
    }

    public function getLastName()
    {
        return $this->order->get_billing_last_name();
    }

    public function getEmail()
    {
        return $this->order->get_billing_email();
    }

    public function getPhone()
    {
        return $this->order->get_billing_phone();
    }

    public function getAddress1()
    {
        return $this->order->get_billing_address_1();
    }

    public function getAddress2()
    {
        return $this->order->get_billing_address_2();
    }

    public function getCity()
    {
        return $this->order->get_billing_city();
    }

    public function getState()
    {
        return $this->order->get_billing_state();
    }

    public function getCountry()
    {
        return $this->order->get_billing_country();
    }

    public function getPostalCode()
    {
        return $this->order->get_billing_postcode();
    }
}