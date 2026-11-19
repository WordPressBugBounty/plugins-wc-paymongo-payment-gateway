<?php

namespace Paymongo\Phaymongo;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;

if (!defined('PAYMONGO_BASE_URL')) {
    /**
     * @deprecated Unused by the library and kept only for backward compatibility
     *             (original value, no trailing slash). Use PaymongoClient::BASE_URL.
     */
    define('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1');
}

class PaymongoClient {
    /** Trailing slash is required so relative request paths keep the /v1 prefix. */
    const BASE_URL = 'https://api.paymongo.com/v1/';

    /** Client options callers are allowed to set. */
    const ALLOWED_CLIENT_OPS = ['unwrap', 'return_response'];

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
            'base_uri' => self::BASE_URL,
            'timeout' => 30,
            'connect_timeout' => 10,
        );

        $this->client = new Client(array_merge($default_guzzle_ops, $guzzle_ops));

        foreach ((array) $client_ops as $key => $value) {
            if (!in_array($key, self::ALLOWED_CLIENT_OPS, true)) {
                throw new InvalidArgumentException(sprintf('Unknown client option "%s".', $key));
            }

            $this->{$key} = (bool) $value;
        }
    }

    /**
     * Keep API keys out of var_dump(), print_r() and most loggers.
     */
    public function __debugInfo()
    {
        return array(
            'public_key' => '***',
            'secret_key' => '***',
            'base_resource_key' => $this->base_resource_key,
            'return_response' => $this->return_response,
            'unwrap' => $this->unwrap,
        );
    }

    protected function getAuthorizationHeader(bool $use_public_key = false) {
        $key = $use_public_key ? $this->public_key : $this->secret_key;

        return 'Basic ' . base64_encode($key);
    }

    /**
     * Builds a relative resource path, URL-encoding every segment so IDs
     * cannot inject extra path parts, query strings or traversal.
     *
     * @param  string|int ...$segments
     * @return string
     */
    protected function resourcePath(...$segments) {
        return implode('/', array_map(function ($segment) {
            return rawurlencode((string) $segment);
        }, $segments));
    }

    public function createRequest(string $method, string $url, ?array $payload = null, bool $use_public_key = false) {
        // Paths must stay relative to BASE_URL: under RFC 3986 a leading slash would
        // resolve against the host root and silently drop the /v1 prefix, and a
        // leading "//" would switch host and send the Authorization header to it.
        $url = ltrim($url, '/');

        $request = new Request($method, $url, array(
            'Authorization' => $this->getAuthorizationHeader($use_public_key),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ));

        if (!empty($payload)) {
            $request = $request->withBody(\GuzzleHttp\Psr7\Utils::streamFor(json_encode($payload, JSON_THROW_ON_ERROR)));
        }

        return $request;
    }

    public function sendRequest($request, $request_opts = []) {
        $final_request_opts = array_merge(['http_errors' => true], $request_opts);

        try {
            $response = $this->client->send($request, $final_request_opts);

            if ($this->return_response) return $response;

            $json = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            if (!$this->unwrap) return $json;

            return $json['data'];
        } catch (ClientException $error) {
            // Only 4xx responses are converted. A 5xx (GuzzleHttp\Exception\ServerException)
            // propagates unchanged: it means Paymongo's side failed and the outcome of a POST
            // is uncertain, which callers must be able to tell apart from an API rejection.
            $response = $error->getResponse();
            $json = null;

            if ($response !== null) {
                try {
                    $json = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    $json = null; // empty or non-JSON error body (e.g. a 502 from a proxy)
                }
            }

            if (is_array($json) && isset($json['errors']) && is_array($json['errors'])) {
                // Deliberately NOT chaining $error: Guzzle exceptions hold the original
                // request, whose Authorization header is the base64-encoded secret key,
                // and print_r()/var_dump() of the exception would expose it.
                throw new PaymongoException($json['errors'], $response->getStatusCode());
            }

            throw $error;
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
        $request = $this->createRequest('POST', $this->resourcePath($this->base_resource_key), $payload, $use_public_key);
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
        $request = $this->createRequest('GET', $this->resourcePath($this->base_resource_key, $id));
        return $this->sendRequest($request, $request_ops);
    }
}
