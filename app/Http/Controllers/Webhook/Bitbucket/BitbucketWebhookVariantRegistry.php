<?php

namespace App\Http\Controllers\Webhook\Bitbucket;

use Illuminate\Http\Request;

class BitbucketWebhookVariantRegistry
{
    /**
     * @var array<int, BitbucketWebhookVariant>
     */
    private array $variants;

    public function __construct(?BitbucketDataCenterWebhookVariant $dataCenter = null, ?BitbucketCloudWebhookVariant $cloud = null)
    {
        $this->variants = [
            $dataCenter ?? new BitbucketDataCenterWebhookVariant,
            $cloud ?? new BitbucketCloudWebhookVariant,
        ];
    }

    public function resolve(Request $request): BitbucketWebhookVariant
    {
        foreach ($this->variants as $variant) {
            if ($variant->supports($request)) {
                return $variant;
            }
        }

        return $this->variants[array_key_last($this->variants)];
    }
}
