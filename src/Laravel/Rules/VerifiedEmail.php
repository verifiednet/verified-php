<?php

declare(strict_types=1);

namespace VerifiedNet\Laravel\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use VerifiedNet\Client;

/**
 * Laravel rule: the email address must not be one Verified.net recommends rejecting (disposable, no mail
 * server, mailbox doesn't exist, ...). Offers the corrected address for typos such as gmial.com.
 *
 *     'email' => ['required', 'email', new VerifiedEmail],
 *
 * Fails open: if the API can't be reached, has no key or the balance is empty, the value passes.
 */
class VerifiedEmail implements ValidationRule
{
    /**
     * @param bool $blockReview   also refuse addresses recommended for review (catch-all, role, typo, ...)
     * @param bool $suggestTypos  refuse likely typos with "Did you mean ...?"
     */
    public function __construct(
        private bool $blockReview = false,
        private bool $suggestTypos = true,
        private ?Client $client = null,
        private string $message = 'The :attribute must be a working, permanent email address.',
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }
        try {
            $result = ($this->client ?? app(Client::class))->email(trim($value));
        } catch (\Throwable $e) {
            return;
        }
        if ($this->suggestTypos && !empty($result['did_you_mean'])) {
            $fail("Did you mean {$result['did_you_mean']}?");
            return;
        }
        $action = $result['recommendation']['action'] ?? null;
        if ($action === 'reject' || ($this->blockReview && $action === 'review')) {
            $fail($this->message);
        }
    }
}
