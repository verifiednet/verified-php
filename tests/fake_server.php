<?php
// Fake Verified.net API for tests/run.php (php -S 127.0.0.1:8765 tests/fake_server.php).
// Records each request to $LOG so the test can check method, path, headers and body.
$log = sys_get_temp_dir() . '/verified-php-fake.log';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$query = $_SERVER['QUERY_STRING'] ?? '';
$body = file_get_contents('php://input');
file_put_contents($log, json_encode([
    'method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'query' => $query,
    'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    'idem' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null, 'body' => json_decode($body, true),
]) . "\n", FILE_APPEND);
header('Content-Type: application/json');
$counter = sys_get_temp_dir() . '/verified-php-fake.429';
$send = function (int $status, $data, array $headers = []) {
    http_response_code($status);
    foreach ($headers as $h) header($h);
    echo is_string($data) ? $data : json_encode($data);
};
$in = json_decode($body, true) ?: [];
switch ($path) {
    case '/v1/email':
        if (($in['address'] ?? '') === 'busy@example.com') {          // 429 once, then OK
            if (!file_exists($counter)) { touch($counter); $send(429, ['error' => ['code' => 'rate_limited', 'message' => 'Slow down', 'request_id' => 'r429']], ['Retry-After: 1']); break; }
        }
        if (($in['address'] ?? '') === 'poor@example.com') {
            $send(402, ['error' => ['code' => 'insufficient_credits', 'message' => 'Not enough credits.', 'request_id' => 'abc123']]); break;
        }
        $map = ['jane@gmial.com' => ['review', 'jane@gmail.com'], 'x@mailinator.com' => ['reject', null],
                'info@example.com' => ['review', null]];
        [$action, $dym] = $map[$in['address'] ?? ''] ?? ['accept', null];
        $send(200, ['valid' => $action !== 'reject', 'did_you_mean' => $dym, 'recommendation' => ['action' => $action, 'reasons' => []], 'credits_charged' => 1]);
        break;
    case '/v1/phone':
        $n = $in['number'] ?? '';
        $send(200, ['valid' => $n !== '123', 'voip' => $n === '+12025550000', 'echo_country' => $in['default_country'] ?? null,
                    'recommendation' => ['action' => $n === '123' ? 'reject' : 'accept', 'reasons' => []]]);
        break;
    case '/v1/account':
        $send(200, ['credits' => ['balance' => 42]]);
        break;
    case '/v1/bulk/jobs':
        $send(201, ['id' => 'job1', 'status' => 'queued']);
        break;
    case '/v1/bulk/jobs/job1':
        $send(200, ['id' => 'job1', 'status' => 'completed']);
        break;
    case '/v1/bulk/jobs/job1/results':
        if (str_contains($query, 'format=csv')) { header('Content-Type: text/csv'); $send(200, "email,vfy_status\na@x.com,ok\n"); break; }
        $page = (int) ($_GET['page'] ?? 1);
        $send(200, ['items' => [['input' => "v{$page}a"], ['input' => "v{$page}b"]], 'has_next' => $page < 2]);
        break;
    default:
        $send(404, ['error' => ['code' => 'not_found', 'message' => 'No such path']]);
}
