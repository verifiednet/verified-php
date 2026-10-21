<?php

declare(strict_types=1);

namespace VerifiedNet;

/**
 * Official PHP client for the Verified.net API (https://verified.net/docs).
 *
 *     $client = new \VerifiedNet\Client();          // reads VERIFIED_KEY from the environment
 *     $result = $client->email('jane@example.com');
 *     if ($result['recommendation']['action'] === 'reject') { ... }
 *
 * Needs ext-curl and ext-json only. Every method returns the API's JSON answer as an array and
 * throws VerifiedError on an error answer.
 */
class Client
{
    public const VERSION = '1.0.0';
    public const DEFAULT_BASE_URL = 'https://api.verified.net';

    private string $apiKey;
    private string $baseUrl;
    private float $timeout;
    private int $maxRetries;

    /**
     * @param string|null $apiKey  your key (default: the VERIFIED_KEY environment variable)
     * @param array{base_url?: string, timeout?: float|int, max_retries?: int} $options
     *        timeout: seconds per request (default 15); max_retries: retries after 429 or a network error (default 2)
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $key = $apiKey ?? (getenv('VERIFIED_KEY') ?: ($_ENV['VERIFIED_KEY'] ?? ''));
        if ($key === '') {
            throw new \InvalidArgumentException('No API key: pass it to new Client() or set VERIFIED_KEY.');
        }
        $this->apiKey = $key;
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = (float) ($options['timeout'] ?? 15);
        $this->maxRetries = (int) ($options['max_retries'] ?? 2);
    }

    // -- lookups -----------------------------------------------------------------------------

    /** Verify an email address (POST /v1/email). */
    public function email(string $address, bool $bypassCache = false): array
    {
        return $this->post('/v1/email', ['address' => $address, 'bypass_cache' => $bypassCache]);
    }

    /** Validate a phone number (POST /v1/phone). $defaultCountry for numbers without a +country code, e.g. "US". */
    public function phone(string $number, ?string $defaultCountry = null, bool $bypassCache = false): array
    {
        return $this->post('/v1/phone', self::clean(['number' => $number, 'default_country' => $defaultCountry,
                                                      'bypass_cache' => $bypassCache]));
    }

    /** Current carrier and porting status of a US/Canada number (POST /v1/carrier). */
    public function carrier(string $number, ?string $defaultCountry = null, bool $bypassCache = false): array
    {
        return $this->post('/v1/carrier', self::clean(['number' => $number, 'default_country' => $defaultCountry,
                                                        'bypass_cache' => $bypassCache]));
    }

    /** Location, network and risk of an IP address (POST /v1/ip). */
    public function ip(string $ip, bool $bypassCache = false): array
    {
        return $this->post('/v1/ip', ['ip' => $ip, 'bypass_cache' => $bypassCache]);
    }

    /** Do a name and/or phone match the records for this email? (POST /v1/contact). */
    public function contact(string $email, ?string $name = null, ?string $phone = null, ?string $defaultCountry = null): array
    {
        return $this->post('/v1/contact', self::clean(['email' => $email, 'name' => $name, 'phone' => $phone,
                                                        'default_country' => $defaultCountry]));
    }

    /** Who is behind a US/Canada number (POST /v1/reverse/phone). $tier: "standard" or "premium". */
    public function reversePhone(string $phone, string $tier = 'standard', ?string $defaultCountry = null,
                                 bool $bypassCache = false): array
    {
        return $this->post('/v1/reverse/phone', self::clean(['phone' => $phone, 'tier' => $tier,
                                                              'default_country' => $defaultCountry,
                                                              'bypass_cache' => $bypassCache]));
    }

    /** Who is behind an email address (POST /v1/reverse/email). */
    public function reverseEmail(string $email, bool $bypassCache = false): array
    {
        return $this->post('/v1/reverse/email', ['email' => $email, 'bypass_cache' => $bypassCache]);
    }

    /** Balance, usage and the key's details (GET /v1/account, free). */
    public function account(): array
    {
        return $this->request('GET', '/v1/account');
    }

    // -- bulk jobs -----------------------------------------------------------------------------

    /**
     * Start a bulk job from a list of values (POST /v1/bulk/jobs).
     *
     * $type: email, phone, ip, carrier, reverse_phone or reverse_email.
     * $options: default_country, tier, webhook_url, idempotency_key. A retry with the same idempotency key within
     * 24 hours returns the same job instead of creating another; one is generated if you don't pass it, so the
     * client's own retries are safe.
     *
     * @param iterable<string> $items
     */
    public function createBulkJob(string $type, iterable $items, array $options = []): array
    {
        $values = [];
        foreach ($items as $item) {
            $values[] = (string) $item;
        }
        $body = self::clean(['type' => $type, 'items' => $values,
                             'default_country' => $options['default_country'] ?? null,
                             'tier' => $options['tier'] ?? null, 'webhook_url' => $options['webhook_url'] ?? null]);
        $key = $options['idempotency_key'] ?? bin2hex(random_bytes(16));
        return $this->request('POST', '/v1/bulk/jobs', $body, ['Idempotency-Key: ' . $key]);
    }

    /** Your recent bulk jobs (GET /v1/bulk/jobs). */
    public function bulkJobs(): array
    {
        return $this->request('GET', '/v1/bulk/jobs')['jobs'];
    }

    /** A job's status and progress (GET /v1/bulk/jobs/{id}). */
    public function bulkJob(string $jobId): array
    {
        return $this->request('GET', '/v1/bulk/jobs/' . rawurlencode($jobId));
    }

    /** Cancel a queued or running job; values not yet checked aren't charged. */
    public function cancelBulkJob(string $jobId): array
    {
        return $this->request('DELETE', '/v1/bulk/jobs/' . rawurlencode($jobId));
    }

    /**
     * Every result item of a job, fetching page after page (JSON format).
     *
     * @return \Generator<int, array>
     */
    public function bulkResults(string $jobId): \Generator
    {
        $page = 1;
        while (true) {
            $data = $this->request('GET', '/v1/bulk/jobs/' . rawurlencode($jobId) . '/results?page=' . $page);
            foreach ($data['items'] as $item) {
                yield $item;
            }
            if (empty($data['has_next'])) {
                return;
            }
            $page++;
        }
    }

    /** The uploaded rows plus result columns, as a CSV string. */
    public function bulkResultsCsv(string $jobId): string
    {
        return $this->request('GET', '/v1/bulk/jobs/' . rawurlencode($jobId) . '/results?format=csv', null, [], true);
    }

    /** Poll until the job is completed or cancelled; returns the final job. */
    public function waitForBulkJob(string $jobId, float $pollSeconds = 5.0, ?float $timeout = null): array
    {
        $deadline = $timeout === null ? null : microtime(true) + $timeout;
        while (true) {
            $job = $this->bulkJob($jobId);
            if (in_array($job['status'], ['completed', 'cancelled'], true)) {
                return $job;
            }
            if ($deadline !== null && microtime(true) > $deadline) {
                throw new \RuntimeException("Bulk job {$jobId} still {$job['status']} after {$timeout} s");
            }
            usleep((int) ($pollSeconds * 1_000_000));
        }
    }

    // -- transport -----------------------------------------------------------------------------

    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    /** @return array|string */
    private function request(string $method, string $path, ?array $body = null, array $headers = [], bool $raw = false)
    {
        $hdrs = array_merge([
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'User-Agent: verified-net-php/' . self::VERSION,
        ], $headers);
        $data = null;
        if ($body !== null) {
            $data = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $hdrs[] = 'Content-Type: application/json';
        }
        $idempotent = $method === 'GET' || $headers !== [];   // only reads and keyed bulk creation are retried after network errors
        $attempt = 0;
        while (true) {
            $respHeaders = [];
            $ch = curl_init($this->baseUrl . $path);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $hdrs,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
                CURLOPT_CONNECTTIMEOUT_MS => (int) (min($this->timeout, 10) * 1000),
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return strlen($line);
                },
            ]);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
            $payload = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($payload === false) {
                if ($idempotent && $attempt < $this->maxRetries) {
                    $attempt++;
                    usleep((2 ** $attempt) * 1_000_000);
                    continue;
                }
                throw new VerifiedError(0, 'network_error', $curlError ?: 'request failed');
            }
            if ($status >= 200 && $status < 300) {
                if ($raw) {
                    return $payload;
                }
                return $payload === '' ? [] : json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            }
            $err = self::errorFrom($status, $payload, $respHeaders);
            if ($status === 429 && $attempt < $this->maxRetries) {
                $attempt++;
                usleep((int) (min($err->retryAfter ?? 2 ** $attempt, 30) * 1_000_000));
                continue;
            }
            throw $err;
        }
    }

    private static function errorFrom(int $status, string $payload, array $headers): VerifiedError
    {
        $body = json_decode($payload, true);
        $err = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
        $retry = $headers['retry-after'] ?? null;
        return new VerifiedError(
            $status,
            (string) ($err['code'] ?? 'http_error'),
            (string) ($err['message'] ?? "HTTP {$status}"),
            $err['request_id'] ?? ($headers['x-request-id'] ?? null),
            is_numeric($retry) ? (float) $retry : null,
        );
    }

    private static function clean(array $values): array
    {
        return array_filter($values, static fn ($v) => $v !== null && $v !== false);
    }
}
