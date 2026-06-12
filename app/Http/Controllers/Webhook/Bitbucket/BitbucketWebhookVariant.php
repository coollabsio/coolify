<?php

namespace App\Http\Controllers\Webhook\Bitbucket;

use Illuminate\Http\Request;

interface BitbucketWebhookVariant
{
    public function supports(Request $request): bool;

    public function eventKey(Request $request): string;

    /**
     * @return array<int, string>
     */
    public function handledEventKeys(): array;

    public function parse(Request $request): ?BitbucketWebhookContext;

    public function unhandledBranchMessage(string $rawEvent): string;
}
