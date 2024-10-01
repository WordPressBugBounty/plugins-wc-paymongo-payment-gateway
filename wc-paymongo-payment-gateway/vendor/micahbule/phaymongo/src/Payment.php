<?php

namespace Paymongo\Phaymongo;

class Payment extends PaymongoClient {
    protected $base_resource_key = 'payments';

    /**
     * A function to create a Paymongo payment object
     *
     * @param  int $amount
     * @param  string $source_id
     * @param  string $source_type
     * @param  string $description
     * @param  string $statement_descriptor
     * @param  mixed $metadata
     * @return mixed
     */
    public function create($amount, $source_id, $description = null, $statement_descriptor = null, $metadata = null) {
        $attributes = array(
            'amount' => $amount * 100,
            'currency' => 'PHP', // hard-coded for now
            'source' => array(
                'id' => $source_id,
                'type' => 'source', // hard-coded for now
            ),
        );

        if (!empty($description)) {
            $attributes['description'] = $description;
        }

        if (!empty($statement_descriptor)) {
            $attributes['statement_descriptor'] = $statement_descriptor;
        }

        if (!empty($metadata)) {
            $attributes['metadata'] = $metadata;
        }

        $payload = PaymongoUtils::constructPayload($attributes);
        return $this->createResource($payload);
    }
    
    /**
     * A function to retrieve a Paymongo payment object by ID
     *
     * @param  string $id
     * @return mixed
     */
    public function retrieveById($id) {
        return $this->retrieveResourceById($id);
    }
    
    /**
     * A function to retrieve multiple Paymongo payment objects
     *
     * @param  int $before
     * @param  int $after
     * @param  int $limit
     * @return mixed
     */
    public function retrieveAll($before = null, $after = null, $limit = null) {
        $queries = array();

        if (!empty($before)) {
            $queries['before'] = $before;
        }

        if (!empty($after)) {
            $queries['after'] = $after;
        }

        if (!empty($limit)) {
            $queries['limit'] = $limit;
        }

        $request = $this->createRequest('GET', '/payments');
        return $this->sendRequest($request, ['query' => $queries]);
    }
}