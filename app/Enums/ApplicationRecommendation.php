<?php

namespace App\Enums;

use App\Models\Application;
use Illuminate\Support\Collection;

/**
 * Settings that Coolify recommends for an application, shown on its Recommended settings page.
 * The values are stored in application_settings.dismissed_recommendations, so do not rename them.
 */
enum ApplicationRecommendation: string
{
    case UseBuildSecrets = 'use-build-secrets';
    case UseRailpack = 'use-railpack';

    /**
     * Recommendations that apply to the application, are not done yet, and are not dismissed.
     *
     * @return Collection<int, self>
     */
    public static function openFor(Application $application): Collection
    {
        return self::unsatisfiedFor($application)
            ->reject(fn (self $recommendation): bool => $recommendation->isDismissedFor($application))
            ->values();
    }

    /**
     * @return Collection<int, self>
     */
    public static function dismissedFor(Application $application): Collection
    {
        return self::unsatisfiedFor($application)
            ->filter(fn (self $recommendation): bool => $recommendation->isDismissedFor($application))
            ->values();
    }

    /**
     * @return Collection<int, self>
     */
    private static function unsatisfiedFor(Application $application): Collection
    {
        return collect(self::cases())
            ->filter(fn (self $recommendation): bool => $recommendation->appliesTo($application) && ! $recommendation->isSatisfied($application));
    }

    public function title(): string
    {
        return match ($this) {
            self::UseBuildSecrets => 'Use build secrets',
            self::UseRailpack => 'Switch from Nixpacks to Railpack',
        };
    }

    public function why(Application $application): string
    {
        return match ($this) {
            self::UseBuildSecrets => collect([
                'Build-time variables are passed to the build as build arguments. Docker saves their values in the image history, so anyone who can read the image can read them.',
                filled($application->docker_registry_image_name)
                    ? "This application pushes its image to {$application->docker_registry_image_name}, so the values ship with the image."
                    : null,
                'With build secrets, the values are available only inside RUN instructions, as environment variables. A Dockerfile that uses them in FROM, COPY, WORKDIR, or other instructions gets empty values there.',
            ])->filter()->implode(' '),
            self::UseRailpack => config('deprecations.nixpacks').' Railpack reads RAILPACK_* variables and railpack.json, not NIXPACKS_* variables or nixpacks.toml. Check them before you redeploy.',
        };
    }

    /**
     * The callout type that shows the recommendation: danger or warning.
     */
    public function severity(Application $application): string
    {
        return match ($this) {
            self::UseBuildSecrets => filled($application->docker_registry_image_name) ? 'danger' : 'warning',
            self::UseRailpack => 'warning',
        };
    }

    public function appliesTo(Application $application): bool
    {
        return match ($this) {
            self::UseBuildSecrets => in_array($application->build_pack, ['dockerfile', 'nixpacks', 'dockercompose'], true)
                && ! ($application->build_pack === 'dockercompose' && $application->settings?->is_raw_compose_deployment_enabled)
                && ($application->environment_variables()->withoutBuildpackControlVariables()->where('is_buildtime', true)->exists()
                    || $application->environment_variables_preview()->withoutBuildpackControlVariables()->where('is_buildtime', true)->exists()),
            self::UseRailpack => $application->build_pack === 'nixpacks',
        };
    }

    public function isSatisfied(Application $application): bool
    {
        return match ($this) {
            self::UseBuildSecrets => (bool) $application->settings?->use_build_secrets,
            self::UseRailpack => $application->build_pack === 'railpack',
        };
    }

    public function isDismissedFor(Application $application): bool
    {
        return in_array($this->value, $application->settings?->dismissed_recommendations ?? [], true);
    }

    public function fixLabel(): string
    {
        return match ($this) {
            self::UseBuildSecrets => 'Turn on build secrets',
            self::UseRailpack => 'Switch to Railpack',
        };
    }

    public function fixNeedsConfirmation(): bool
    {
        return match ($this) {
            self::UseBuildSecrets => false,
            self::UseRailpack => true,
        };
    }

    /**
     * Changes the application and its settings in memory. The caller authorizes and saves both.
     */
    public function applyFix(Application $application): void
    {
        match ($this) {
            self::UseBuildSecrets => $application->settings->use_build_secrets = true,
            self::UseRailpack => $this->switchToRailpack($application),
        };
    }

    private function switchToRailpack(Application $application): void
    {
        $application->build_pack = 'railpack';

        // Same as a build pack change on the General page: read-only labels follow the build pack.
        if ($application->settings->is_container_label_readonly_enabled) {
            $application->custom_labels = base64_encode(implode("\n", generateLabelsApplication($application)));
        }
    }
}
