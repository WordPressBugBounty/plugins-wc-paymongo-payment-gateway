<?php

namespace Paymongo\Phaymongo;

class PaymentMethod extends PaymongoClient {
    protected $base_resource_key = 'payment_methods';

    /**
     * A function to create a Paymongo payment method object
     *
     * @param  string $type
     * @param  mixed $details
     * @param  mixed $billing
     * @param  mixed $metadata
     * @return mixed
     */
    public function create($type, $details = null, $billing = null, $metadata = null) {
        $attributes = array(
            'type' => $type,
        );

        if (!empty($details)) {
            $attributes['details'] = $details;
        }

        if (!empty($billing)) {
            $attributes['billing'] = $billing;
        }

        if (!empty($metadata)) {
            $attributes['metadata'] = $metadata;
        }

        $payload = PaymongoUtils::constructPayload($attributes);
        return $this->createResource($payload);
    }
    
    /**
     * A function to retrieve a Paymongo payment method object by ID
     *
     * @param  string $id
     * @return mixed
     */
    public function retrieveById($id) {
        return $this->retrieveResourceById($id);
    }
}