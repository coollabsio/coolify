<?php

return [
    'rate_limit' => env('API_RATE_LIMIT', 200),
    // Failed requests (401, 403, 404) per minute for one token or source IP before it gets 429. 0 turns it off.
    'failed_request_rate_limit' => env('API_FAILED_REQUEST_RATE_LIMIT', 30),
];
