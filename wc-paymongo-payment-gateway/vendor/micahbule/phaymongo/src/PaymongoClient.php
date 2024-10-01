<?php

namespace Paymongo\Phaymongo;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Utils;

define('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1');

class PaymongoClient {
    protected $public_key;
    protected $secret_key;
    protected $client;
    protected $base_resource_key;
    protected $return_response = false;
    protected $unwrap = true;

    public function __construct(string $public_key, string $secret_key, $client_ops = array(), array $guzzle_ops = array())
    {
        $this->public_key = $public_key;
        $this->secret_key = $secret_key;

        $default_guzzle_ops = array(
            'base_uri' => PAYMONGO_BASE_URL,
        );
        
        $client = new Client(array_merge($default_guzzle_ops, $guzzle_ops));

        $final_client_ops = array_merge(array(), $client_ops);

        $this->client = $client;

        foreach ($final_client_ops as $key => $value){
            $this->{$key} = $value;
        }
    }

    protected function getAuthorizationHeader(bool $use_public_key = false) {
        $key = $use_public_key ? $this->public_key : $this->secret_key;

        return 'Basic ' . base64_encode($key);
    }

    public function createRequest(string $method, string $url, array $payload = null, bool $use_public_key = false) {
        $request = new Request($method, $url, array(
            'Authorization' => $this->getAuthorizationHeader($use_public_key),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ));

        if (!empty($payload)) {
            $request = $request->withBody(\GuzzleHttp\Psr7\Utils::streamFor(json_encode($payload)));
        }

        return $request;
    }

    public function sendRequest($request, $request_opts = []) {
        $final_request_opts = array_merge(['http_errors' => true], $request_opts);

        try {
            $response = $this->client->send($request, $final_request_opts);
    
            if ($this->return_response) return $response;
    
            $json = Utils::jsonDecode($response->getBody()->__toString(), true);
    
            if (!$this->unwrap) return $json;
    
            return $json['data'];
        } catch (ClientException $error) {
            $json = Utils::jsonDecode($error->getResponse()->getBody()->__toString(), true);

            if (array_key_exists('errors', $json)) {
                throw new PaymongoException($json['errors']);
            } else {
                throw $error;
            }
        }
    }
    
    /**
     * A function to create a Paymongo resource object
     *
     * @param  mixed $payload
     * @param  bool $use_public_key
     * @param  mixed $request_ops
     * @return mixed
     */
    public function createResource($payload, $use_public_key = false, $request_ops = []) {
        $request = $this->createRequest('POST', '/' . $this->base_resource_key, $payload, $use_public_key);
        return $this->sendRequest($request, $request_ops);
    }

    /**
     * A function to get a Paymongo resource object by ID
     *
     * @param  string $id
     * @param  mixed $request_ops
     * @return mixed
     */
    public function retrieveResourceById($id, $request_ops = []) {
        $request = $this->createRequest('GET', '/' . $this->base_resource_key .  '/' . $id);
        return $this->sendRequest($request, $request_ops);
    }
}