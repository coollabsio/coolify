<?php

namespace App\Enums;

use App\Models\Application;
use Illuminate\Support\Collection;

/**
 * Settings that Coolify recommends for an application, shown in the Recommendations section of its General page.
 * The values are stored in application_settings.dismissed_recommendations, so do not rename them.
 */
enum ApplicationRecommendation: string
{
    case UseBuildSecrets = 'use-build-secrets';
    case UseRailpack = 'use-railpack';
    case UseLatestComposeParser = 'use-latest-compose-parser';

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
            self::UseLatestComposeParser => 'Migrate to the latest Compose parser',
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
            self::UseLatestComposeParser => "Coolify parses the Compose file of this application with parser {$application->compose_parsing_version}. The migration makes a copy with the latest parser and the volume data. This application stays as it is.",
        };
    }

    /**
     * The callout type that shows the recommendation: danger or warning.
     */
    public function severity(Application $application): string
    {
        return match ($this) {
            self::UseBuildSecrets => filled($application->docker_registry_image_name) ? 'danger' : 'warning',
            self::UseRailpack, self::UseLatestComposeParser => 'warning',
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
            self::UseLatestComposeParser => $application->usesLegacyComposeParser(),
        };
    }

    public function isSatisfied(Application $application): bool
    {
        return match ($this) {
            self::UseBuildSecrets => (bool) $application->settings?->use_build_secrets,
            self::UseRailpack => $application->build_pack === 'railpack',
            self::UseLatestComposeParser => false,
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
            self::UseLatestComposeParser => 'Migrate',
        };
    }

    public function fixNeedsConfirmation(): bool
    {
        return match ($this) {
            self::UseBuildSecrets, self::UseLatestComposeParser => false,
            self::UseRailpack => true,
        };
    }

    /**
     * The page that does the fix, for a fix that is more than a settings change. Null when applyFix() does it.
     */
    public function fixUrl(Application $application): ?string
    {
        return match ($this) {
            self::UseLatestComposeParser => route('project.application.resource-operations', [
                'project_uuid' => $application->environment->project->uuid,
                'environment_uuid' => $application->environment->uuid,
                'application_uuid' => $application->uuid,
            ]).'#migrate-parser-section',
            default => null,
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
            self::UseLatestComposeParser => throw new \LogicException('The parser migration is done on the Resource Operations page.'),
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
