<?php

/**
 * @runInSeparateProcess
 */

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Paymongo\Phaymongo\PaymongoClient;
use Paymongo\Phaymongo\PaymongoException;

it('can create a request without payload', function () {
    $client = new PaymongoClient('a', 'b');

    $request = $client->createRequest('GET', '/');

    expect($request->hasHeader('Authorization'))->toBeTrue();
    expect($request->getHeader('Authorization')[0])->toBe('Basic ' . base64_encode('b'));
    expect((string) $request->getBody())->toBe('');
});

it('can create a request with payload', function () {
    $client = new PaymongoClient('a', 'b');

    $request = $client->createRequest('POST', '/', ['foo' => 'bar']);

    expect($request->hasHeader('Authorization'))->toBeTrue();
    expect($request->getHeader('Authorization')[0])->toBe('Basic ' . base64_encode('b'));
    expect((string) $request->getBody())->toBe('{"foo":"bar"}');
});

it('can send a request and return unwrapped data by default', function () {
    $mockHandler = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '{"data":"bar"}'),
    ]);

    $handlerStack = HandlerStack::create($mockHandler);
    $client = new PaymongoClient('a', 'b', [], ['handler' => $handlerStack]);

    $request = $client->createRequest('GET', '/');
    $response = $client->sendRequest($request);

    expect($response)->toBe('bar');
});

it('can send a request and return wrapped data', function () {
    $mockHandler = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '{"data":"bar"}'),
    ]);

    $handlerStack = HandlerStack::create($mockHandler);
    $client = new PaymongoClient('a', 'b', ['unwrap' => false], ['handler' => $handlerStack]);

    $request = $client->createRequest('GET', '/');
    $response = $client->sendRequest($request);

    expect($response)->toBe(['data' => 'bar']);
});

it('can send a request and return the HTTP message instance', function () {
    $mockHandler = new MockHandler([
        new Response(200, ['Content-Type' => 'application/json'], '{"data":"bar"}'),
    ]);

    $handlerStack = HandlerStack::create($mockHandler);
    $client = new PaymongoClient('a', 'b', ['return_response' => true], ['handler' => $handlerStack]);

    $request = $client->createRequest('GET', '/');
    $response = $client->sendRequest($request);

    expect($response)->toBeInstanceOf(Response::class);
    expect($response->getBody()->__toString())->toBe('{"data":"bar"}');
});

it('can send a request and throw a paymongo exception', function () {
    $mockHandler = new MockHandler([
        new ClientException('Some error happened', new Request('GET', '/'), new Response(400, ['Content-Type' => 'application/json'], '{"errors":[{"detail": "some error"}]}')),
    ]);

    $handlerStack = HandlerStack::create($mockHandler);
    $client = new PaymongoClient('a', 'b', [], ['handler' => $handlerStack]);

    $request = $client->createRequest('GET', '/');
    try {
        $client->sendRequest($request);
    } catch (PaymongoException $e) {
        $formattedMessages = $e->format_errors();

        expect($formattedMessages[0])->toBe('some error');

        throw $e;
    }
})->throws(PaymongoException::class);

it('rethrows the Guzzle exception when a 4xx JSON body has no errors key', function () {
    $mockHandler = new MockHandler([
        new ClientException('Some error happened', new Request('GET', '/'), new Response(400, ['Content-Type' => 'application/json'], '{}')),
    ]);

    $handlerStack = HandlerStack::create($mockHandler);
    $client = new PaymongoClient('a', 'b', [], ['handler' => $handlerStack]);

    $request = $client->createRequest('GET', '/');
    $client->sendRequest($request);
})->throws(ClientException::class);

it('can send a successful request and throw an error from the API', function () {
    $mockHandler = new MockHandler([
        new Response(400, ['Content-Type' => 'application/json'], '{"errors":[{"detail": "some error"}]}'),
    ]);

    $handlerStack = HandlerStack::create($mockHandler);
    $client = new PaymongoClient('a', 'b', [], ['handler' => $handlerStack]);

    $request = $client->createRequest('GET', '/');
    $client->sendRequest($request);
})->throws(PaymongoException::class);

it('can create a resource', function () {
    $client = \Mockery::mock('Paymongo\Phaymongo\PaymongoClient')->makePartial();

    $payload = ['data' => 'bar'];

    $client->shouldReceive('createRequest')
        ->withArgs(['POST', '', $payload, false])
        ->atLeast()
        ->times(1)
        ->andReturn(true);

    $client->shouldReceive('sendRequest')
        ->withArgs([true, []])
        ->atLeast()
        ->times(1);

    $client->createResource($payload);
});

it('can retrieve a resource by ID', function () {
    $client = \Mockery::mock('Paymongo\Phaymongo\PaymongoClient')->makePartial();

    $id = 1;

    $client->shouldReceive('createRequest')
        ->withArgs(['GET', '/' . $id])
        ->atLeast()
        ->times(1)
        ->andReturn(true);

    $client->shouldReceive('sendRequest')
        ->withArgs([true, []])
        ->atLeast()
        ->times(1);

    $client->retrieveResourceById($id);
});

it('rejects unknown client options', function () {
    new PaymongoClient('a', 'b', ['secret_key' => 'attacker']);
})->throws(InvalidArgumentException::class);

it('url-encodes resource ids so they cannot alter the path', function () {
    $client = new class('a', 'b') extends PaymongoClient {
        public function path(...$segments)
        {
            return $this->resourcePath(...$segments);
        }
    };

    expect($client->path('payments', '../refunds?x=1#y'))->toBe('payments/..%2Frefunds%3Fx%3D1%23y');
});

it('sends requests to the /v1 API path', function () {
    $history = [];
    $handlerStack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"data":"ok"}'),
    ]));
    $handlerStack->push(\GuzzleHttp\Middleware::history($history));

    $client = new PaymongoClient('a', 'b', [], ['handler' => $handlerStack]);
    $client->sendRequest($client->createRequest('GET', 'payments/pay_1'));

    expect((string) $history[0]['request']->getUri())->toBe('https://api.paymongo.com/v1/payments/pay_1');
});

it('rethrows the Guzzle exception for non-JSON error bodies', function () {
    $handlerStack = HandlerStack::create(new MockHandler([
        new Response(502, [], '<html>Bad Gateway</html>'),
    ]));
    $client = new PaymongoClient('a', 'b', [], ['handler' => $handlerStack]);

    $client->sendRequest($client->createRequest('GET', 'payments'));
})->throws(\GuzzleHttp\Exception\ServerException::class);

it('throws a JsonException for a malformed success body', function () {
    $handlerStack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{not json'),
    ]));
    $client = new PaymongoClient('a', 'b', [], ['handler' => $handlerStack]);

    $client->sendRequest($client->createRequest('GET', 'payments'));
})->throws(\JsonException::class);

it('does not expose the secret key when a PaymongoException is dumped', function () {
    $handlerStack = HandlerStack::create(new MockHandler([
        new Response(422, [], '{"errors":[{"detail":"bad"}]}'),
    ]));
    $client = new PaymongoClient('pk_test_public', 'sk_test_secret', [], ['handler' => $handlerStack]);

    try {
        $client->sendRequest($client->createRequest('GET', 'payments'));
    } catch (PaymongoException $e) {
        ob_start();
        var_dump($e);
        print_r($e);
        $dump = ob_get_clean();

        expect($dump)
            ->not->toContain('sk_test_secret')
            ->not->toContain(base64_encode('sk_test_secret'))
            ->not->toContain(base64_encode('pk_test_public'));
        expect($e->getPrevious())->toBeNull();
        return;
    }

    throw new \Exception('Expected PaymongoException');
});

it('falls back to the error code, then a generic message, when an error has no detail', function () {
    $e = new PaymongoException([['code' => 'parameter_invalid'], ['foo' => 'bar'], 'oops']);

    expect($e->format_errors())->toBe(['parameter_invalid', 'Unknown error', 'Unknown error']);
});

it('applies default timeouts and lets guzzle options override them', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"data":1}'),
        new Response(200, [], '{"data":1}'),
    ]));
    $stack->push(\GuzzleHttp\Middleware::history($history));

    $default = new PaymongoClient('a', 'b', [], ['handler' => $stack]);
    $default->sendRequest($default->createRequest('GET', 'payments'));

    $custom = new PaymongoClient('a', 'b', [], ['handler' => $stack, 'timeout' => 5, 'connect_timeout' => 2]);
    $custom->sendRequest($custom->createRequest('GET', 'payments'));

    expect($history[0]['options']['timeout'])->toBe(30);
    expect($history[0]['options']['connect_timeout'])->toBe(10);
    expect($history[1]['options']['timeout'])->toBe(5);
    expect($history[1]['options']['connect_timeout'])->toBe(2);
});

it('includes the status code and errors on paymongo errors', function () {
    $handlerStack = HandlerStack::create(new MockHandler([
        new Response(422, [], '{"errors":[{"detail":"bad"}]}'),
    ]));
    $client = new PaymongoClient('a', 'b', [], ['handler' => $handlerStack]);

    try {
        $client->sendRequest($client->createRequest('GET', 'payments'));
    } catch (PaymongoException $e) {
        expect($e->getCode())->toBe(422);
        expect($e->getErrors())->toBe([['detail' => 'bad']]);
        return;
    }

    throw new \Exception('Expected PaymongoException');
});

it('masks api keys in debug output', function () {
    $client = new PaymongoClient('pk_live_secret', 'sk_live_secret');

    ob_start();
    var_dump($client);
    print_r($client);
    $dump = ob_get_clean();

    expect($dump)->not->toContain('sk_live_secret')->not->toContain('pk_live_secret');
});

it('sends float amounts as exact integer centavos', function () {
    $history = [];
    $handlerStack = HandlerStack::create(new MockHandler([
        new Response(200, [], '{"data":{}}'),
    ]));
    $handlerStack->push(\GuzzleHttp\Middleware::history($history));

    $refund = new \Paymongo\Phaymongo\Refund('a', 'b', [], ['handler' => $handlerStack]);
    $refund->create(19.99, 'pay_1', 'others');

    $body = json_decode((string) $history[0]['request']->getBody(), true);
    expect($body['data']['attributes']['amount'])->toBe(1999);
});

it('keeps the /v1 prefix when a caller passes a leading-slash path', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"data":"ok"}')]));
    $stack->push(\GuzzleHttp\Middleware::history($history));

    $client = new PaymongoClient('a', 'b', [], ['handler' => $stack]);
    $client->sendRequest($client->createRequest('GET', '/payments/pay_1'));

    expect((string) $history[0]['request']->getUri())->toBe('https://api.paymongo.com/v1/payments/pay_1');
});

it('keeps throwing the Guzzle ServerException for a 5xx, even with an errors body', function () {
    // A 5xx means Paymongo's side failed and the outcome of a POST is uncertain, which is
    // not the same as a PaymongoException ("Paymongo understood and rejected the request").
    // Consumers classify the two differently, so 5xx must not be converted.
    $stack = HandlerStack::create(new MockHandler([
        new Response(500, [], '{"errors":[{"code":"internal_error","detail":"x"}]}'),
    ]));
    $client = new PaymongoClient('a', 'b', [], ['handler' => $stack]);

    $client->sendRequest($client->createRequest('GET', 'payments'));
})->throws(\GuzzleHttp\Exception\ServerException::class);

it('rethrows the Guzzle ClientException for a 4xx with a non-JSON body', function () {
    $stack = HandlerStack::create(new MockHandler([
        new Response(400, [], '<html>Bad Request</html>'),
    ]));
    $client = new PaymongoClient('a', 'b', [], ['handler' => $stack]);

    $client->sendRequest($client->createRequest('GET', 'payments'));
})->throws(\GuzzleHttp\Exception\ClientException::class);

it('rethrows the Guzzle exception when errors is not an array', function () {
    $stack = HandlerStack::create(new MockHandler([
        new Response(422, [], '{"errors":"oops"}'),
    ]));
    $client = new PaymongoClient('a', 'b', [], ['handler' => $stack]);

    $client->sendRequest($client->createRequest('GET', 'payments'));
})->throws(\GuzzleHttp\Exception\ClientException::class);

it('does not let a protocol-relative path switch hosts', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"data":"ok"}')]));
    $stack->push(\GuzzleHttp\Middleware::history($history));

    $client = new PaymongoClient('a', 'b', [], ['handler' => $stack]);
    $client->sendRequest($client->createRequest('GET', '//evil.test/steal'));

    $uri = $history[0]['request']->getUri();
    expect($uri->getHost())->toBe('api.paymongo.com');
    expect((string) $uri)->toBe('https://api.paymongo.com/v1/evil.test/steal');
});
