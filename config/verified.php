<?php

// Verified.net settings for Laravel. Publish with:
//     php artisan vendor:publish --tag=verified-config
return [
    // Your API key from https://app.verified.net/keys
    'key' => env('VERIFIED_KEY'),

    // Seconds to wait for an answer. Validation rules let the form through if the API is slower than this.
    'timeout' => (float) env('VERIFIED_TIMEOUT', 5),

    // Country assumed for phone numbers typed without a +country code.
    'default_country' => env('VERIFIED_DEFAULT_COUNTRY', 'US'),

    'base_url' => env('VERIFIED_BASE_URL', 'https://api.verified.net'),
];
