<?php

namespace App\Traits\C3;

use Spatie\Url\Url;

/**
 * Connect3 fork: slug-based staging hostnames on Application (PRD section 5.1).
 *
 * Coolify's default domain for a new application is "<uuid>.<server wildcard or sslip>".
 * Whenever such a default is about to be saved for an application whose project has a
 * client slug, it is replaced with "<slug>.<staging-apex>" (or "<name>--<slug>.<apex>"
 * for further applications) and the preview template is switched to "pr-<n>--<slug>".
 *
 * Operator-chosen domains are never touched. This runs before Application::booted()'s own
 * saving hook, so upstream normalisation still applies to the rewritten value.
 */
trait HasStagingFqdn
{
    public static function bootHasStagingFqdn(): void
    {
        static::saving(function ($application) {
            if (! $application->isDirty('fqdn') || blank($application->fqdn)) {
                return;
            }
            if (! c3_enabled()) {
                return;
            }
            $project = data_get($application, 'environment.project');
            if (! $project || blank($project->client_slug)) {
                return;
            }

            $domains = array_map('trim', explode(',', $application->fqdn));
            $changed = false;
            foreach ($domains as $i => $domain) {
                try {
                    $host = Url::fromString($domain)->getHost();
                } catch (\Throwable) {
                    continue;
                }
                if (str_starts_with(strtolower($host), strtolower($application->uuid).'.')) {
                    $staging = $project->stagingHostFor($application);
                    if ($staging) {
                        $domains[$i] = "https://{$staging}";
                        $changed = true;
                    }
                }
            }
            if ($changed) {
                $application->fqdn = implode(',', array_unique($domains));
            }
            if (blank($application->preview_url_template) || $application->preview_url_template === '{{pr_id}}.{{domain}}') {
                $application->preview_url_template = C3_PREVIEW_URL_TEMPLATE;
            }
        });
    }

    /**
     * Bare hostnames of every domain on this application.
     *
     * @return array<int, string>
     */
    public function c3Hosts(): array
    {
        if (blank($this->fqdn)) {
            return [];
        }
        $hosts = [];
        foreach (explode(',', $this->fqdn) as $domain) {
            try {
                $hosts[] = strtolower(Url::fromString(trim($domain))->getHost());
            } catch (\Throwable) {
                continue;
            }
        }

        return array_values(array_filter(array_unique($hosts)));
    }
}
