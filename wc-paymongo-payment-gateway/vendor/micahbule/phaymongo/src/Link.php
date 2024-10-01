<?php

namespace Paymongo\Phaymongo;

class Link extends PaymongoClient {
    protected $base_resource_key = 'links';

    public function create($amount, $description, $remarks = null) {
        $attributes = array(
            'amount' => $amount,
            'description' => $description,
        );

        if (!empty($remarks)) {
            $attributes['remarks'] = $remarks;
        }

        $payload = PaymongoUtils::constructPayload($attributes);
        return $this->createResource($payload);
    }

    public function retrieveById($id) {
        return $this->retrieveResourceById($id);
    }

    public function retrieveByReferenceNumber($refNum) {
        $request = $this->createRequest('GET', '/' . $this->base_resource_key);
        return $this->sendRequest($request, array('query' => array('reference_number' => $refNum)));
    }

    public function archive($id) {
        $request = $this->createRequest('POST', '/' . $this->base_resource_key . '/' . $id . '/archive');
        return $this->sendRequest($request);
    }

    public function unarchive($id) {
        $request = $this->createRequest('POST', '/' . $this->base_resource_key . '/' . $id . '/unarchive');
        return $this->sendRequest($request);
    }
}