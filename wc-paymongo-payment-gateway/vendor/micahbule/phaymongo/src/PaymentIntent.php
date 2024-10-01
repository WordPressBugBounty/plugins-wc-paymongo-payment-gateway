<?php

namespace Paymongo\Phaymongo;

class PaymentIntent extends PaymongoClient {
    protected $base_resource_key = 'payment_intents';

    /**
     * A function to create a Paymongo payment intent object to use for transactions
     *
     * @param  int $amount The transaction amount
     * @param  string[] $payment_methods
     * @param  string $description
     * @param  mixed $metadata
     * @return mixed
     */
    public function create($amount, $payment_method_allowed, $payment_method_options = null, $description = null, $metadata = null, $currency = 'PHP') {
        $attributes = array(
            'amount' => $amount * 100,
            'payment_method_allowed' => $payment_method_allowed,
            'currency' => $currency,
        );

        if (!empty($description)) {
            $attributes['description'] = $description;
        }

        if (!empty($metadata)) {
            $attributes['metadata'] = $metadata;
        }

        if (!empty($payment_method_options)) {
            $attributes['payment_method_options'] = $payment_method_options;
        }

        $payload = PaymongoUtils::constructPayload($attributes);
        return $this->createResource($payload);
    }

    /**
     * A function to retrieve a Paymongo payment intent object by ID
     *
     * @param  string $id
     * @return mixed
     */
    public function retrieveById($id) {
        return $this->retrieveResourceById($id);
    }

    /**
     * A function to attach a Paymongo payment method to a payment intent object
     *
     * @param  string $payment_intent_id
     * @param  string $payment_method_id
     * @param  string $return_url
     * @param  string $client_key
     * @return mixed
     */
    public function attachPaymentMethod($payment_intent_id, $payment_method_id, $payment_method_options = null, $return_url = null, $client_key = null) {
        $attributes = array(
            'payment_method' => $payment_method_id,
        );

        if (!empty($client_key)) {
            $attributes['client_key'] = $client_key;
        }

        if (!empty($return_url)) {
            $attributes['return_url'] = $return_url;
        }

        if (!empty($payment_method_options)) {
            $attributes['payment_method_options'] = $payment_method_options;
        }

        $payload = PaymongoUtils::constructPayload($attributes);
        $request = $this->createRequest('POST', '/payment_intents/' . $payment_intent_id . '/attach', $payload);
        return $this->sendRequest($request);
    }
}