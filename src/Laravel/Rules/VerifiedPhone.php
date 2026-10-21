<?php

declare(strict_types=1);

namespace VerifiedNet\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use VerifiedNet\Client;

/**
 * Laravel rule: the phone number must be valid and not one Verified.net recommends rejecting
 * (unassigned range, many spam reports, ...).
 *
 *     'phone' => ['required', new VerifiedPhone],                  // default country from config (US)
 *     'phone' => ['required', new VerifiedPhone(blockVoip: true)], // also refuse internet (VoIP) numbers
 *
 * Fails open: if the API can't be reached, has no key or the balance is empty, the value passes.
 */
class VerifiedPhone implements ValidationRule
{
    /**
     * @param string|null $defaultCountry country for numbers without a +country code (default: config verified.default_country)
     * @param bool $blockReview also refuse numbers recommended for review
     * @param bool $blockVoip   refuse VoIP numbers
     */
    public function __construct(
        private ?string $defaultCountry = null,
        private bool $blockReview = false,
        private bool $blockVoip = false,
        private ?Client $client = null,
        private string $message = 'The :attribute must be a valid phone number.',
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }
        try {
            $country = $this->defaultCountry ?? (string) config('verified.default_country', 'US');
            $result = ($this->client ?? app(Client::class))->phone(trim($value), $country ?: null);
        } catch (\Throwable $e) {
            return;
        }
        $action = $result['recommendation']['action'] ?? null;
        if (($result['valid'] ?? null) === false || $action === 'reject'
            || ($this->blockReview && $action === 'review')
            || ($this->blockVoip && ($result['voip'] ?? null) === true)) {
            $fail($this->message);
        }
    }
}
