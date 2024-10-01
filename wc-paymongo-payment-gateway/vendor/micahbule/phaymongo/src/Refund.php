<?php

namespace Paymongo\Phaymongo;

class Refund extends PaymongoClient {
    protected $base_resource_key = 'refunds';
    
    /**
     * A function to create a Paymongo refund object
     *
     * @param  int $amount
     * @param  string $payment_id
     * @param  string $reason
     * @param  string $notes
     * @param  mixed $metadata
     * @return mixed
     */
    public function create($amount, $payment_id, $reason, $notes = null, $metadata = null) {
        $attributes = array(
            'amount' => $amount * 100,
            'payment_id' => $payment_id,
            'reason' => $reason,
        );

        if (!empty($notes)) {
            $attributes['notes'] = $notes;
        }

        if (!empty($metadata)) {
            $attributes['metadata'] = $metadata;
        }

        $payload = PaymongoUtils::constructPayload($attributes);
        return $this->createResource($payload);
    }

    /**
     * A function to retrieve a Paymongo refund object by ID
     *
     * @param  string $id
     * @return mixed
     */
    public function retrieveById($id) {
        return $this->retrieveResourceById($id);
    }
}