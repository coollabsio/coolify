<?php

namespace App\Traits;

trait ProxyTimeout
{
    protected static function bootProxyTimeout(): void
    {
        static::saving(function ($model) {
            $model->normalizeProxyTimeout();
        });
    }

    protected function normalizeProxyTimeout(): void
    {
        if (blank($this->proxy_timeout)) {
            $this->proxy_timeout = null;

            return;
        }

        $this->proxy_timeout = max(0, (int) $this->proxy_timeout);
    }
}
