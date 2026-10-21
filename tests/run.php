<?php
// Tests for the PHP client against tests/fake_server.php. Run from the package root:
//   php -S 127.0.0.1:8765 tests/fake_server.php &  php tests/run.php
declare(strict_types=1);
require __DIR__ . '/../src/VerifiedError.php';
require __DIR__ . '/../src/Client.php';

use VerifiedNet\Client;
use VerifiedNet\VerifiedError;

$log = sys_get_temp_dir() . '/verified-php-fake.log';
@unlink($log); @unlink(sys_get_temp_dir() . '/verified-php-fake.429');
$passed = 0;
function check(bool $ok, string $label): void { global $passed; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) exit(1); $passed++; }
function last(): array { global $log; $lines = file($log, FILE_IGNORE_NEW_LINES); return json_decode(end($lines), true); }

$c = new Client('vfy_test_key', ['base_url' => 'http://127.0.0.1:8765/', 'timeout' => 5]);

$r = $c->email('jane@example.com');
$l = last();
check($r['recommendation']['action'] === 'accept', 'email: result as array');
check($l['method'] === 'POST' && $l['path'] === '/v1/email' && $l['body'] === ['address' => 'jane@example.com', 'bypass_cache' => false], 'email: POST body');
check($l['auth'] === 'Bearer vfy_test_key' && str_starts_with($l['ua'], 'verified-net-php/'), 'auth header and user agent');

$r = $c->phone('(202) 456-1111', 'US');
check(last()['body'] === ['number' => '(202) 456-1111', 'default_country' => 'US'], 'phone: default_country sent, false/null left out');
$c->phone('+12024561111');
check(last()['body'] === ['number' => '+12024561111'], 'phone: no default_country');

$t = microtime(true);
$r = $c->email('busy@example.com');
check($r['recommendation']['action'] === 'accept' && microtime(true) - $t >= 0.9, '429 retried after Retry-After');

try { $c->email('poor@example.com'); check(false, '402 throws'); }
catch (VerifiedError $e) {
    check($e->status === 402 && $e->getCode() === 402 && $e->errorCode === 'insufficient_credits'
          && $e->apiMessage === 'Not enough credits.' && $e->requestId === 'abc123', '402: VerifiedError fields');
}

check($c->account()['credits']['balance'] === 42 && last()['method'] === 'GET', 'account: GET');

$job = $c->createBulkJob('email', new ArrayIterator(['a@x.com', 'b@x.com']), ['webhook_url' => 'https://example.com/hook']);
$l = last();
check($job['id'] === 'job1' && $l['body'] === ['type' => 'email', 'items' => ['a@x.com', 'b@x.com'], 'webhook_url' => 'https://example.com/hook']
      && strlen((string) $l['idem']) === 32, 'bulk create: body + generated Idempotency-Key');
$c->createBulkJob('ip', ['1.1.1.1'], ['idempotency_key' => 'mine']);
check(last()['idem'] === 'mine', 'bulk create: own Idempotency-Key');
check($c->waitForBulkJob('job1', 0.1)['status'] === 'completed', 'waitForBulkJob');
$items = iterator_to_array($c->bulkResults('job1'), false);
check(array_column($items, 'input') === ['v1a', 'v1b', 'v2a', 'v2b'], 'bulkResults: pages followed');
check($c->bulkResultsCsv('job1') === "email,vfy_status\na@x.com,ok\n", 'bulkResultsCsv: raw CSV');

try { $c->bulkJob('missing'); check(false, '404 throws'); }
catch (VerifiedError $e) { check($e->status === 404 && $e->errorCode === 'not_found', '404 mapped'); }

$dead = new Client('k', ['base_url' => 'http://127.0.0.1:9', 'max_retries' => 0, 'timeout' => 2]);
try { $dead->email('a@b.com'); check(false, 'network error throws'); }
catch (VerifiedError $e) { check($e->status === 0 && $e->errorCode === 'network_error', 'network error: status 0'); }

putenv('VERIFIED_KEY');
try { new Client(); check(false, 'no key throws'); } catch (InvalidArgumentException $e) { check(true, 'no key: InvalidArgumentException'); }
putenv('VERIFIED_KEY=from_env');
$c2 = new Client(null, ['base_url' => 'http://127.0.0.1:8765']);
$c2->account();
check(last()['auth'] === 'Bearer from_env', 'key from VERIFIED_KEY');

echo "{$passed} checks passed\n";
