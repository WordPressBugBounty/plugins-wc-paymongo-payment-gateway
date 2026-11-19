# Phaymongo
An unofficial simple API client for Paymongo written in PHP

[![codecov](https://codecov.io/gh/CynderTech/phaymongo/branch/main/graph/badge.svg)](https://codecov.io/gh/CynderTech/phaymongo)

## Installation
`composer require cyndertech/phaymongo`

Requires PHP 7.4 or newer. CI runs the full test suite on PHP 7.4 through 8.5. Upgrading from `micahbule/phaymongo`? See [CHANGELOG.md](CHANGELOG.md) — the package name changed but the `Paymongo\Phaymongo` namespace did not.

## Usage
Simply use the `Phaymongo` class and call the resource method to create a resource class. In the examples below, we'll be creating a payment intent.
```php
<?php

use Paymongo\Phaymongo\Phaymongo;

function pay() {
    $client = new Phaymongo('YOUR_PUBLIC_KEY', 'YOUR_SECRET_KEY');
    $paymentIntent = $client->paymentIntent()->create(100, ['gcash', 'card']);

    // do something with the payment intent
}
```
By default, this returns the actual payment intent data from within the [`data` property](https://developers.paymongo.com/reference/the-payment-method-object) of the response payload.  
If you want your client to return the original response payload, just pass the `unwrap` config with `false` value on the options array when you instantiate the client:
```php
$client = new Phaymongo('YOUR_PUBLIC_KEY', 'YOUR_SECRET_KEY', ['unwrap' => false]);
```

The client throws an error if your request fails. You can handle this simply by wrapping it within a `try-catch` block.
```php
use Paymongo\Phaymongo\Phaymongo;

function pay() {
    try {
        $client = new Phaymongo('YOUR_PUBLIC_KEY', 'YOUR_SECRET_KEY');
        $paymentIntent = $client->paymentIntent()->create(100, ['gcash', 'card']);

        // do something with the payment intent
    } catch (\Paymongo\Phaymongo\PaymongoException $e) {
        /** To get the error details of a failed request, just do the following */
        $errors = $e->format_errors();

        // do something with the error
    }
}
```

## Timeouts and retries
Requests time out after 30 seconds (10 seconds to connect). Override them with the fourth constructor argument, e.g. `new Phaymongo($pk, $sk, [], ['timeout' => 10])`. A timeout throws a Guzzle `ConnectException` / `RequestException` rather than a `PaymongoException`.
**A timed-out `POST` (creating a payment intent, refund, link, etc.) may still have succeeded at Paymongo.** Look the resource up, or use your own reference/metadata, before retrying so a customer isn't charged or refunded twice.

## Logging errors safely
Request objects carry your secret key in the `Authorization` header (base64-encoded, not encrypted), and some exceptions hold on to the request:
* `PaymongoException` and the client objects mask keys in `var_dump()` / `print_r()` output.
* Any other exception thrown while a request is in flight still references the original request, either as a property or in its stack trace. That includes Guzzle exceptions that are **not** Paymongo API errors (connection failures and timeouts, every 5xx response, and 4xx responses without an `errors` array) and a `JsonException` from a malformed response.

So in production, log `$e->getMessage()` and `$e->getTraceAsString()` rather than `print_r($e, true)` or `var_dump($e)`, and set `zend.exception_ignore_args = On` in `php.ini` (the default in PHP's production template) so stack traces don't record request objects.

## Development
Run the tests with `composer test` on PHP 8.2+ (Pest 3), or `composer test:legacy` on PHP 7.4–8.1 (Pest 1 / PHPUnit 9).

## Features
* PSR-4 and PSR-7 Compliant
* Easy, intuitive API design

## Supported Paymongo Resources
* [Payment Intents](https://developers.paymongo.com/reference/the-payment-intent-object)
* [Payment Methods](https://developers.paymongo.com/reference/the-payment-method-object)
* [Sources](https://developers.paymongo.com/reference/the-sources-object)
* [Payments](https://developers.paymongo.com/reference/payment-source)
* [Refunds](https://developers.paymongo.com/reference/refund-resource)
* [Links](https://developers.paymongo.com/reference/links-resource)

## API Documentation
All **fields in bold** are required fields whereas ***fields in italics*** are optional. Note that this library uses GuzzleHttp client set with the base Paymongo API URL so URLs below are relative.

### new Phaymongo($public_key, $secret_key, $client_ops)
#### Parameters
* **public_key** - Your Paymongo Public Key
* **secret_key** - Your Paymongo Secret Key
* ***client_ops.unwrap*** - This is a boolean that determines whether your client will return the resource info nested in the data property of the original response payload. ***Default to true***.
* ***client_ops.return_response*** - This is a boolean that determines if the client will return the original HTTP message object for further processing. Unknown option names throw an `InvalidArgumentException`.
#### Methods
* [**paymentIntent()**](#payment-intent-resource) - Returns a Payment Intent resource object
* [**paymentMethod()**](#payment-method-resource) - Returns a Payment Method resource object
* [**source()**](#source-resource) - Returns a Source resource object
* [**payment()**](#payment-resource) - Returns a Payment resource object
* [**refund()**](#refund-resource) - Returns a Refund resource object
* [**link()**](#link-resource) - Returns a Link resource object

### new PaymongoClient($public_key, $secret_key, $client_ops)
Parent API client class. If for some reason you need to access resources manually, you can instantiate or extend this class and use the methods below.
#### Parameters
* **public_key** - Your Paymongo Public Key
* **secret_key** - Your Paymongo Secret Key
* ***client_ops.unwrap*** - This is a boolean that determines whether your client will return the resource info nested in the data property of the original response payload. ***Default to true***.
* ***client_ops.return_response*** - This is a boolean that determines if the client will return the original HTTP message object for further processing. Unknown option names throw an `InvalidArgumentException`.
#### Methods
##### createRequest($method, $url, $payload, $use_public_key)
Constructs a Request object to be sent by the HTTP client
* **method** - The HTTP verb to use for the request
* **url** - The path of the resource you want to send the request to (ex. /payment_intents).
* ***payload*** - Any payload to be sent with the requests (ex. creating a resource)
* ***use_public_key*** - A boolean value to instruct the client to use the public key to authenticate the request via headers. ***Default to false and uses secret key***.
##### sendRequest($request, $request_opts)
Takes in the Request object and executes the request to the Paymongo resource endpoint
* **request** - Request object from `createRequest()` method
* ***request_opts*** - Any Request options, if any. Refer [here for more info](https://docs.guzzlephp.org/en/stable/request-options.html).  
---
### [Payment Intent Resource](https://developers.paymongo.com/reference/the-payment-intent-object)
#### create($amount, $payment_method_allowed, $payment_method_options, $description, $metadata)
A function to create a Paymongo payment intent object.
* **amount** - The transaction amount.
* **payment_method_allowed** - An array of payment method strings allowed to use. For more information on available payment methods, check [Payment Intent Resource](https://developers.paymongo.com/reference/create-a-paymentintent).
* ***payment_method_options*** - An object containing payment method options. Used in conjunction with [Card Installments](https://developers.paymongo.com/docs/installments) feature.
* ***description*** - The description of the transaction.
* ***metadata*** - a JSON object where you can store any other info that you might need for integration such as transaction reference numbers.

#### retrieveById($id)
A function to retrieve a Paymongo payment intent object by ID.
* **id** - The payment intent ID

#### attachPaymentMethod($payment_intent_id, $payment_method_id, $payment_method_options, $return_url, $client_key)
A function to attach a Paymongo payment method to a payment intent object
* **payment_intent_id** - The payment intent ID.
* **payment_method_id** - The payment method ID.
* ***payment_method_options*** - An object containing payment method options. Used in conjunction with [Card Installments](https://developers.paymongo.com/docs/installments) feature.
* ***return_url*** - The URL where your users will be redirected to after a successful or failed payment authorization step.
* ***client_key*** - Client key fromm payment intent if it is created using a public key -- usually a case if the payment intent was created from the frontend.

### [Payment Method Resource](https://developers.paymongo.com/reference/the-payment-method-object)
#### create($type, $details, $billing, $metadata)
A function to create a Paymongo payment method object.
* **type** - The type of payment method. For more information on available payment methods, check [Payment Method Resource](https://developers.paymongo.com/reference/create-a-paymentmethod).
* ***details*** - Only required for `card` type transactions. Contains details of the credit card itself. Check [Payment Method Resource](https://developers.paymongo.com/reference/create-a-paymentmethod) for more details.
* ***billing*** - Contains the billing details of the user. Check [Payment Method Resource](https://developers.paymongo.com/reference/create-a-paymentmethod) for more details.
* ***metadata*** - a JSON object where you can store any other info that you might need for integration such as transaction reference numbers.

#### retrieveById($id)
A function to retrieve a Paymongo payment method object by ID
* **id** - The payment method ID.

### [Source Resource](https://developers.paymongo.com/reference/the-sources-object)
#### create($amount, $type, $success_url, $failed_url, $billing, $metadata)
A function to create a Paymongo source object.
* **amount** - The amount of the transaction.
* **type** - The type of source. Currently only supports the values `gcash` and `grab_pay`.
* **success_url** - This is where the user gets redirected after successful payment authorization and processing.
* **failed_Url** - This is where the user gets redirected after unsuccessful payment authorization and processing.
* ***billing*** - Contains the billing details of the user. Check [Source Resource](https://developers.paymongo.com/reference/create-a-source) for more details.
* ***metadata*** - a JSON object where you can store any other info that you might need for integration such as transaction reference numbers.

#### retrieveById($id)
A function to retrieve a Paymongo source object by ID.
* **id** - The payment method ID.

### [Payment Resource](https://developers.paymongo.com/reference/payment-source)
***Currently only used in conjunction with Sources for E-wallet payment methods such as GCash or GrabPay***
#### create($amount, $source_id, $description, $statement_descriptor, $metadata)
A function to create a Paymongo payment object.
* **amount** - The amount of the payment. Should be equal to the amount of the source as partial payments are not allowed yet.
* **source_id** - The source ID to be paid
* ***description*** - The description of the payment.
* ***statement_descriptor*** - This is what would appear on your users' statement of accounts as the description of the transaction.
* ***metadata*** - a JSON object where you can store any other info that you might need for integration such as transaction reference numbers.

#### retrieveById(id)
A function to retrieve a Paymongo payment object by ID.
* **id** - The payment ID.

#### retrieveAll($before, $after, $limit)
A function to retrieve multiple Paymongo payment objects
* ***before*** - A cursor to use in pagination. For more details, check [Payment Resource](https://developers.paymongo.com/reference/list-all-payments).
* ***after*** - A cursor to use in pagination. For more details, check [Payment Resource](https://developers.paymongo.com/reference/list-all-payments).
* ***limit*** - For limiting the result set of your query. 10 is the default value.

### [Refund Resource](https://developers.paymongo.com/reference/refund-resource)
#### create($amount, $payment_id, $reason, $notes, $metadata)
A function to create a Paymongo refund object.
* **amount** - The amount to be refunded.
* **payment_id** - The payment ID to process the refund for.
* **reason** - The reason of the refund. Check [Payment Resource](https://developers.paymongo.com/reference/create-a-refund) for all available reasons.
* ***notes*** - Internal notes where you can put remarks about the refund.
* ***metadata*** - a JSON object where you can store any other info that you might need for integration such as transaction reference numbers.

#### retrieveById($id)
A function to retrieve a Paymongo refund object by ID.
* **id** - The payment ID.

### [Link Resource](https://developers.paymongo.com/reference/links-resource)
#### create($amount, $description, $remarks)
A function to create a Paymongo link resource.
* **amount** - The amount of the transaction.
* **description** - The description of the transaction.
* ***remarks*** - For internal notes. This value is not displayed on the checkout page that the user will see upon clicking the generated link.

#### retrieveById($id)
* **id** - The link ID.

#### retrieveByReferenceNumber($refNum)
* **refNum** - The reference number.

#### archive($id)
* **id** - The link ID.

#### unarchive($id)
* **id** - The link ID.

## Why?
First, why not? Second, we needed to standardize libraries between several products including:
* [Payments via Paymongo for WooCommerce](https://wordpress.org/plugins/wc-paymongo-payment-gateway/#installation)
* [Paymongo Payments](https://marketplace.magento.com/cyndertech-paymongo-gateway.html) -- ***to be updated***

Plus, it's dev community contribution.

Why Phaymongo? It's PHP + Paymongo. :)