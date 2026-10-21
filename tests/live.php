<?php
// The PHP client against a real (local) Verified.net API. Run by api/tests/clients_php_smoke.sh, which starts the
// server and creates a throwaway account; BASE and KEY come from the environment.
declare(strict_types=1);
require __DIR__ . '/../src/VerifiedError.php';
require __DIR__ . '/../src/Client.php';

use VerifiedNet\Client;
use VerifiedNet\VerifiedError;

$passed = 0;
function check(bool $ok, string $label): void { global $passed; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) exit(1); $passed++; }
function fails(callable $fn, int $status, string $code): bool {
    try { $fn(); return false; } catch (VerifiedError $e) { return $e->status === $status && $e->errorCode === $code; }
}

$base = getenv('BASE');
$c = new Client(getenv('KEY'), ['base_url' => $base]);

$start = $c->account()['credits']['balance'];
$r = $c->ip('8.8.8.8');
check($r['valid'] === true && $r['credits_charged'] === 1 && isset($r['recommendation']['action']), 'ip(): real lookup, 1 credit');
check($c->ip('8.8.8.8')['credits_charged'] === 0, 'repeat within 7 days is free');
check($c->account()['credits']['balance'] === $start - 1, 'account(): balance went down by 1');
$r = $c->email('not-an-email');
check($r['valid'] === false && $r['credits_charged'] === 0, 'email(): invalid input is free');
$r = $c->phone('(202) 456-1111', 'US');
check($r['valid'] === true && $r['e164'] === '+12024561111', 'phone(): default country');

$e = null;
try { (new Client('vfy_live_wrong', ['base_url' => $base]))->account(); } catch (VerifiedError $err) { $e = $err; }
check($e !== null && $e->status === 401 && strlen((string) $e->requestId) === 24, 'bad key: 401 with request id');

$tag = bin2hex(random_bytes(4));
$job = $c->createBulkJob('ip', ['8.8.4.4', '1.1.1.1', '8.8.4.4'], ['idempotency_key' => "php-$tag"]);
check($job['status'] === 'queued' && $job['unique_values'] === 2 && $job['rows'] === 3, 'createBulkJob(): 3 rows, 2 unique');
check($c->createBulkJob('ip', ['8.8.4.4'], ['idempotency_key' => "php-$tag"])['id'] === $job['id'], 'same idempotency key: same job');
check(in_array($job['id'], array_column($c->bulkJobs(), 'id'), true), 'bulkJobs() lists it');
check($c->cancelBulkJob($job['id'])['status'] === 'cancelled', 'cancelBulkJob()');
check($c->waitForBulkJob($job['id'], 0.1)['status'] === 'cancelled', 'waitForBulkJob()');
$items = iterator_to_array($c->bulkResults($job['id']), false);
check(count($items) === 2 && $items[0]['status'] === 'cancelled', 'bulkResults()');
check(str_starts_with($c->bulkResultsCsv($job['id']), 'input,'), 'bulkResultsCsv()');
check(fails(fn () => $c->bulkJob('00000000-0000-0000-0000-000000000000'), 404, 'not_found'), 'unknown job: 404');

echo "{$passed} checks passed\n";
