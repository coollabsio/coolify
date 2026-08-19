<?php

namespace App\Rules;

/**
 * Backwards-compatible name for outbound URL validation.
 *
 * External service URLs use the same private-target allowlist as webhooks
 * and S3 endpoints.
 */
class SafeExternalUrl extends SafeWebhookUrl {}
