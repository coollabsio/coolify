<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ValidCloudInitYaml implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * Validates that the cloud-init script is either:
     * - Valid YAML format (for cloud-config)
     * - Valid bash script (starting with #!)
     * - Empty/null (optional field)
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        $script = trim($value);

        // If it's a bash script (starts with shebang), skip YAML validation
        if (str_starts_with($script, '#!')) {
            return;
        }

        // If it's a cloud-config file (starts with #cloud-config), validate YAML
        if (str_starts_with($script, '#cloud-config')) {
            // Remove the #cloud-config header and validate the rest as YAML
            $yamlContent = preg_replace('/^#cloud-config\s*/m', '', $script, 1);

            try {
                Yaml::parse($yamlContent);
            } catch (ParseException $e) {
                $fail('The :attribute must be valid YAML format. Error: '.$e->getMessage());
            }

            return;
        }

        // If it doesn't start with #! or #cloud-config, try to parse as YAML
        // (some users might omit the #cloud-config header)
        try {
            Yaml::parse($script);
        } catch (ParseException $e) {
            $fail('The :attribute must be either a valid bash script (starting with #!) or valid cloud-config YAML. YAML parse error: '.$e->getMessage());
        }
    }
}
