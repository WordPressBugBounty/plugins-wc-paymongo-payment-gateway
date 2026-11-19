# Changelog

## 2.0.0

### Breaking
- Package renamed from `micahbule/phaymongo` to `cyndertech/phaymongo` (`replace`s the old name). The `Paymongo\Phaymongo` namespace is unchanged.
- Requires PHP `^7.4 || ^8.0`, `guzzlehttp/guzzle ^7.15.2` and `guzzlehttp/psr7 ^2.12.3`. The psr7 1.x line is no longer supported because it is affected by published security advisories and has no patched release.
- `client_ops` now only accepts `unwrap` and `return_response`; any other key throws `InvalidArgumentException`. Previously any property (including `secret_key`) could be overwritten.
- Requests now resolve under `https://api.paymongo.com/v1/`. The old base URI plus absolute paths silently dropped the `/v1` prefix. Paths passed to the public `createRequest()` with a leading slash (e.g. `/payments/pay_x`) are normalised so they also keep `/v1`. Verify behaviour against your Paymongo account before upgrading.
- Amounts are converted with `(int) round($amount * 100)` instead of `$amount * 100`, so `19.99` is sent as `1999` rather than `1998.9999999999998`.
- `PaymongoException::getCode()` now returns the HTTP status code (4xx) instead of `0`.
- A malformed JSON body on a successful response now throws `\JsonException` instead of Guzzle's `InvalidArgumentException`.
- `PaymongoException` no longer chains the Guzzle exception as `getPrevious()`. The Guzzle exception holds the original request, whose `Authorization` header is the base64-encoded secret key.
- `PaymongoException::format_errors()` falls back to the error `code`, then `"Unknown error"`, when an error has no `detail`.

### Unchanged on purpose
- 5xx responses still throw Guzzle's `ServerException`, even when the body has an `errors` array. Only 4xx responses with an `errors` array become `PaymongoException`, as in 1.x.
- `Link::create()` still sends `amount` as given, without the ×100 conversion the other resources use, because existing plugins depend on it.

### Security
- URL-encode all resource IDs placed in request paths.
- Default HTTP timeouts (30s total, 10s connect); override through `$guzzle_ops`.
- JSON encoding failures throw instead of sending an empty body.
- A 4xx response with an empty or non-JSON body no longer crashes error handling; the Guzzle `ClientException` is rethrown. `PaymongoException` now carries the HTTP status code and `getErrors()`.
- Minimum Guzzle and `guzzlehttp/psr7` versions are set above the versions affected by the advisories that `composer audit` reported at release time.
- API keys are masked in `var_dump()` / `print_r()` output of the client, the `Phaymongo` facade and `PaymongoException` (including its stack trace).

### Tooling
- CI runs the full Pest suite on PHP 7.4–8.5 (Pest 1 / PHPUnit 9 on 7.4–8.1 via `phpunit9.xml`, Pest 3 on 8.2+), a `--prefer-lowest` job, `composer audit`, and a smoke test.
- `composer test` no longer uses `--parallel`.
- `composer.lock` is no longer committed (library).
