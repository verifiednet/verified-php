# verifiednet/verified-net

Official PHP client for the [Verified.net](https://verified.net) API: email verification, phone validation, live
carrier and porting lookups, IP intelligence, contact verification, reverse lookups and bulk jobs. Includes
Laravel validation rules. No dependencies beyond `ext-curl` and `ext-json`; PHP 8.1+.

```bash
composer require verifiednet/verified-net
```

## Quick start

Create an API key at [app.verified.net](https://app.verified.net/keys) and set it as `VERIFIED_KEY`.

```php
use VerifiedNet\Client;
use VerifiedNet\VerifiedError;

$client = new Client();  // or new Client('vfy_live_...')

$result = $client->email('jane.doe@gmial.com');
echo $result['recommendation']['action'];  // review
echo $result['did_you_mean'];              // jane.doe@gmail.com

$phone = $client->phone('(202) 456-1111', 'US');
var_dump($phone['line_type'], $phone['voip']);

try {
    $client->carrier('+12024561111');
} catch (VerifiedError $e) {
    echo $e->status, ' ', $e->errorCode, ' ', $e->apiMessage, ' ', $e->requestId;
}
```

Every result has `recommendation.action`: `accept`, `review` or `reject`, with `reasons`. Field reference:
[verified.net/docs](https://verified.net/docs).

## Laravel

The service provider is discovered automatically. Add your key to `.env`:

```dotenv
VERIFIED_KEY=vfy_live_...
```

Then use the rules in any validation:

```php
use VerifiedNet\Laravel\Rules\VerifiedEmail;
use VerifiedNet\Laravel\Rules\VerifiedPhone;

$request->validate([
    'email' => ['required', 'email', new VerifiedEmail],
    'phone' => ['required', new VerifiedPhone],
]);
```

- `VerifiedEmail` refuses addresses the API recommends rejecting (disposable, no mail server, mailbox doesn't
  exist, ...) and answers likely typos with "Did you mean jane@gmail.com?". Options:
  `new VerifiedEmail(blockReview: true)` also refuses catch-all, role and other "review" addresses;
  `suggestTypos: false` turns off the typo message; `message:` changes the error text (`:attribute` works).
- `VerifiedPhone` refuses invalid numbers and ones the API recommends rejecting. Options: `defaultCountry: 'CA'`
  (default from config, `US`), `blockVoip: true`, `blockReview: true`, `message:`.
- Both **fail open**: if the API is slow (over `VERIFIED_TIMEOUT`, 5 s by default), unreachable, has no key or the
  balance is empty, the value passes, so your form never breaks because of us.
- Put Laravel's cheap rules (`email`, `required`) first: junk input then never costs a request. The same value
  checked again within 7 days is free.

Settings (`.env`): `VERIFIED_KEY`, `VERIFIED_TIMEOUT`, `VERIFIED_DEFAULT_COUNTRY`. To edit the config file:
`php artisan vendor:publish --tag=verified-config`. The shared client is `app(\VerifiedNet\Client::class)`.

## Methods

| Method | Endpoint | Credits |
|---|---|---|
| `email($address)` | `POST /v1/email` | 1 |
| `phone($number, $defaultCountry = null)` | `POST /v1/phone` | 1 |
| `carrier($number)` | `POST /v1/carrier` (US/Canada) | 3 |
| `ip($ip)` | `POST /v1/ip` | 1 |
| `contact($email, $name = null, $phone = null)` | `POST /v1/contact` | 1 |
| `reversePhone($phone, $tier = 'standard')` | `POST /v1/reverse/phone` | 10 / 25, only when found |
| `reverseEmail($email)` | `POST /v1/reverse/email` | 10, only when found |
| `account()` | `GET /v1/account` | free |

Invalid input is free, and the same value checked again within 7 days is free. Pass `bypassCache: true` to force
a fresh (charged) check.

## Bulk jobs

```php
$job = $client->createBulkJob('email', ['a@example.com', 'b@example.org']);
$job = $client->waitForBulkJob($job['id']);
foreach ($client->bulkResults($job['id']) as $item) {
    echo $item['input'], ' ', $item['status'], "\n";
}
file_put_contents('results.csv', $client->bulkResultsCsv($job['id']));
```

Types: `email`, `phone`, `ip`, `carrier`, `reverse_phone`, `reverse_email`. Up to 100,000 values per job. Options:
`default_country`, `tier`, `webhook_url` (signed POST when the job finishes), `idempotency_key` (one is generated
if you don't pass it, so retrying never creates a second job).

## Errors and retries

API errors throw `VerifiedError` with `status` (also `getCode()`), `errorCode` (`insufficient_credits`,
`rate_limited`, `unauthorized`, `invalid_request`, ...), `apiMessage` and `requestId` (quote it to support). A 429
is retried after `Retry-After` (`max_retries` 2 by default, `new Client($key, ['max_retries' => 0])` to turn off);
network errors are retried for reads and bulk job creation only, so a lookup is never charged twice by a retry.

## License

MIT
