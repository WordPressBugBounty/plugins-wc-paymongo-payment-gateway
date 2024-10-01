<?php

namespace Paymongo\Phaymongo;

use Exception;

class PaymongoException extends Exception {
    protected $paymongo_errors = [];

    public function __construct($errors)
    {
        parent::__construct('A payment error occured. Please try again.');

        $this->paymongo_errors = $errors;
    }

    public function format_errors() {
        return array_map(function ($error) {
            return $error['detail'];
        }, $this->paymongo_errors);
    }
}