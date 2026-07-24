<?php

return [
    // Anthropic API key for the Railway assistant. Set ANTHROPIC_API_KEY in .env.
    'api_key' => env('ANTHROPIC_API_KEY'),

    // Model + loop budget. Opus 4.8 is the default per Anthropic guidance.
    'model' => env('RAILWAY_AGENT_MODEL', 'claude-opus-4-8'),
    'max_tokens' => (int) env('RAILWAY_AGENT_MAX_TOKENS', 2048),

    // Max API round-trips per user turn (guards against tool-call loops).
    'max_steps' => (int) env('RAILWAY_AGENT_MAX_STEPS', 8),

    'endpoint' => env('RAILWAY_AGENT_ENDPOINT', 'https://api.anthropic.com/v1/messages'),
    'anthropic_version' => '2023-06-01',
    'timeout' => (int) env('RAILWAY_AGENT_TIMEOUT', 120),
];
