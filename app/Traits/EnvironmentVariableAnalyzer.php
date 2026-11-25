<?php

namespace App\Traits;

trait EnvironmentVariableAnalyzer
{
    /**
     * List of environment variables that commonly cause build issues when set to production values.
     * Each entry contains the variable pattern and associated metadata.
     */
    protected static function getProblematicBuildVariables(): array
    {
        return [
            'NODE_ENV' => [
                'problematic_values' => ['production', 'prod'],
                'affects' => 'Node.js/npm/yarn/bun/pnpm',
                'issue' => 'Skips devDependencies installation which are often required for building (webpack, typescript, etc.)',
                'recommendation' => 'Uncheck "Available at Buildtime" or use "development" during build',
            ],
            'NPM_CONFIG_PRODUCTION' => [
                'problematic_values' => ['true', '1', 'yes'],
                'affects' => 'npm/pnpm',
                'issue' => 'Forces npm to skip devDependencies',
                'recommendation' => 'Remove from build-time variables or set to false',
            ],
            'YARN_PRODUCTION' => [
                'problematic_values' => ['true', '1', 'yes'],
                'affects' => 'Yarn/pnpm',
                'issue' => 'Forces yarn to skip devDependencies',
                'recommendation' => 'Remove from build-time variables or set to false',
            ],
            'COMPOSER_NO_DEV' => [
                'problematic_values' => ['1', 'true', 'yes'],
                'affects' => 'PHP/Composer',
                'issue' => 'Skips require-dev packages which may include build tools',
                'recommendation' => 'Set as "Runtime only" or remove from build-time variables',
            ],
            'MIX_ENV' => [
                'problematic_values' => ['prod', 'production'],
                'affects' => 'Elixir/Phoenix',
                'issue' => 'Production mode may skip development dependencies needed for compilation',
                'recommendation' => 'Use "dev" for build or set as "Runtime only"',
            ],
            'RAILS_ENV' => [
                'problematic_values' => ['production'],
                'affects' => 'Ruby on Rails',
                'issue' => 'May affect asset precompilation and dependency handling',
                'recommendation' => 'Consider using "development" for build phase',
            ],
            'RACK_ENV' => [
                'problematic_values' => ['production'],
                'affects' => 'Ruby/Rack',
                'issue' => 'May affect dependency handling and build behavior',
                'recommendation' => 'Consider using "development" for build phase',
            ],
            'BUNDLE_WITHOUT' => [
                'problematic_values' => ['development', 'test', 'development:test'],
                'affects' => 'Ruby/Bundler',
                'issue' => 'Excludes gem groups that may contain build dependencies',
                'recommendation' => 'Remove from build-time variables or adjust groups',
            ],
            'FLASK_ENV' => [
                'problematic_values' => ['production'],
                'affects' => 'Python/Flask',
                'issue' => 'May affect debug mode and development tools availability',
                'recommendation' => 'Usually safe, but consider "development" for complex builds',
            ],
            'DJANGO_SETTINGS_MODULE' => [
                'problematic_values' => [], // Check if contains 'production' or 'prod'
                'affects' => 'Python/Django',
                'issue' => 'Production settings may disable debug tools needed during build',
                'recommendation' => 'Use development settings for build phase',
                'check_function' => 'checkDjangoSettings',
            ],
            'APP_ENV' => [
                'problematic_values' => ['production', 'prod'],
                'affects' => 'Laravel/Symfony',
                'issue' => 'May affect dependency installation and build optimizations',
                'recommendation' => 'Consider using "local" or "development" for build',
            ],
            'ASPNETCORE_ENVIRONMENT' => [
                'problematic_values' => ['Production'],
                'affects' => '.NET/ASP.NET Core',
                'issue' => 'May affect build-time configurations and optimizations',
                'recommendation' => 'Usually safe, but verify build requirements',
            ],
            'CI' => [
                'problematic_values' => ['true', '1', 'yes'],
                'affects' => 'Various tools',
                'issue' => 'Changes behavior in many tools (disables interactivity, changes caching)',
                'recommendation' => 'Usually beneficial for builds, but be aware of behavior changes',
            ],
        ];
    }

    /**
     * Analyze an environment variable for potential build issues.
     * Always returns a warning if the key is in our list, regardless of value.
     */
    public static function analyzeBuildVariable(string $key, string $value): ?array
    {
        $problematicVars = self::getProblematicBuildVariables();

        // Direct key match
        if (isset($problematicVars[$key])) {
            $config = $problematicVars[$key];

            // Check if it has a custom check function
            if (isset($config['check_function'])) {
                $method = $config['check_function'];
                if (method_exists(self::class, $method)) {
                    return self::{$method}($key, $value, $config);
                }
            }

            // Always return warning for known problematic variables
            return [
                'variable' => $key,
                'value' => $value,
                'affects' => $config['affects'],
                'issue' => $config['issue'],
                'recommendation' => $config['recommendation'],
            ];
        }

        return null;
    }

    /**
     * Analyze multiple environment variables for potential build issues.
     */
    public static function analyzeBuildVariables(array $variables): array
    {
        $warnings = [];

        foreach ($variables as $key => $value) {
            $warning = self::analyzeBuildVariable($key, $value);
            if ($warning) {
                $warnings[] = $warning;
            }
        }

        return $warnings;
    }

    /**
     * Custom check for Django settings module.
     */
    protected static function checkDjangoSettings(string $key, string $value, array $config): ?array
    {
        // Always return warning for DJANGO_SETTINGS_MODULE when it's set as build-time
        return [
            'variable' => $key,
            'value' => $value,
            'affects' => $config['affects'],
            'issue' => $config['issue'],
            'recommendation' => $config['recommendation'],
        ];
    }

    /**
     * Generate a formatted warning message for deployment logs.
     */
    public static function formatBuildWarning(array $warning): array
    {
        $messages = [
            "⚠️ Build-time environment variable warning: {$warning['variable']}={$warning['value']}",
            "   Affects: {$warning['affects']}",
            "   Issue: {$warning['issue']}",
            "   Recommendation: {$warning['recommendation']}",
        ];

        return $messages;
    }

    /**
     * Check if a variable should show a warning in the UI.
     */
    public static function shouldShowBuildWarning(string $key): bool
    {
        return isset(self::getProblematicBuildVariables()[$key]);
    }

    /**
     * Get UI warning message for a specific variable.
     */
    public static function getUIWarningMessage(string $key): ?string
    {
        $problematicVars = self::getProblematicBuildVariables();

        if (! isset($problematicVars[$key])) {
            return null;
        }

        $config = $problematicVars[$key];
        $problematicValuesStr = implode(', ', $config['problematic_values']);

        return "Setting {$key} to {$problematicValuesStr} as a build-time variable may cause issues. {$config['issue']} Consider: {$config['recommendation']}";
    }

    /**
     * Get problematic variables configuration for frontend use.
     */
    public static function getProblematicVariablesForFrontend(): array
    {
        $vars = self::getProblematicBuildVariables();
        $result = [];

        foreach ($vars as $key => $config) {
            // Skip the check_function as it's PHP-specific
            $result[$key] = [
                'problematic_values' => $config['problematic_values'],
                'affects' => $config['affects'],
                'issue' => $config['issue'],
                'recommendation' => $config['recommendation'],
            ];
        }

        return $result;
    }
}
