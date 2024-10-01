<?php

namespace Paymongo\Phaymongo;

class Source extends PaymongoClient {
    protected $base_resource_key = 'sources';

    /**
     * A function to create a Paymongo source object to use for transactions
     *
     * @param  int $amount The transaction amount
     * @param  string $type
     * @param  string $success_url
     * @param  string $failed_url
     * @param  object $billing
     * @param  object $metadata
     * @return mixed
     */
    public function create($amount, $type, $success_url, $failed_url, $billing = null, $metadata = null, $currency = 'PHP') {
        $attributes = array(
            'type' => $type,
            'amount' => $amount * 100,
            'currency' => $currency,
            'redirect' => array(
                'success' => $success_url,
                'failed' => $failed_url,
            ),
        );

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
     * A function to retrieve a Paymongo source object by ID
     *
     * @param  string $id
     * @return mixed
     */
    public function retrieveById($id) {
        return $this->retrieveResourceById($id);
    }
}