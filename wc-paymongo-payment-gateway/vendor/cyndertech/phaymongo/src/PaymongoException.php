<?php

namespace Paymongo\Phaymongo;

use Exception;
use Throwable;

class PaymongoException extends Exception {
    protected $paymongo_errors = [];

    public function __construct($errors, int $status_code = 0, ?Throwable $previous = null)
    {
        parent::__construct('A payment error occured. Please try again.', $status_code, $previous);

        $this->paymongo_errors = $errors;
    }

    /**
     * @return array The raw error objects returned by Paymongo
     */
    public function getErrors() {
        return $this->paymongo_errors;
    }

    /**
     * var_dump()/print_r() of an exception include its stack trace, and with
     * zend.exception_ignore_args=Off the trace holds the arguments of
     * PaymongoClient::sendRequest(), i.e. the Request whose Authorization header
     * is the base64-encoded secret key. Leave the trace out of dumps.
     */
    public function __debugInfo()
    {
        return array(
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'paymongo_errors' => $this->paymongo_errors,
        );
    }

    public function format_errors() {
        return array_map(function ($error) {
            if (is_array($error)) {
                return $error['detail'] ?? $error['code'] ?? 'Unknown error';
            }

            return 'Unknown error';
        }, $this->paymongo_errors);
    }
}
