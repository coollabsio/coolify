<?php

namespace App\Console\Commands\Generate;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;

class Services extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $signature = 'generate:services';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Generates service-templates json file based on /templates/compose directory';

    public function handle(): int
    {
        $serviceTemplatesJson = collect(array_merge(
            glob(base_path('templates/compose/*.yaml')),
            glob(base_path('templates/compose/*.yml'))
        ))
            ->mapWithKeys(function ($file): array {
                $file = basename($file);
                $parsed = $this->processFile($file);

                return $parsed === false ? [] : [
                    Arr::pull($parsed, 'name') => $parsed,
                ];
            })->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents(base_path('templates/'.config('constants.services.file_name')), $serviceTemplatesJson.PHP_EOL);

        // Generate service-templates.json with SERVICE_URL changed to SERVICE_FQDN
        $this->generateServiceTemplatesWithFqdn();

        return self::SUCCESS;
    }

    private function processFile(string $file): false|array
    {
        $content = file_get_contents(base_path("templates/compose/$file"));

        $data = collect(explode(PHP_EOL, $content))->mapWithKeys(function ($line): array {
            preg_match('/^#(?<key>.*):(?<value>.*)$/U', $line, $m);

            return $m ? [trim($m['key']) => trim($m['value'])] : [];
        });

        if (str($data->get('ignore'))->toBoolean()) {
            $this->info("Ignoring $file");

            return false;
        }

        $this->info("Processing $file");

        $documentation = $data->get('documentation');
        $documentation = $documentation ? $documentation.'?utm_source=coolify.io' : 'https://coolify.io/docs';

        $json = Yaml::parse($content);
        $compose = base64_encode(Yaml::dump($json, 10, 2));

        $tags = str($data->get('tags'))->lower()->explode(',')->map(fn ($tag) => trim($tag))->filter();
        $tags = $tags->isEmpty() ? null : $tags->all();

        $payload = [
            'name' => pathinfo($file, PATHINFO_FILENAME),
            'documentation' => $documentation,
            'slogan' => $data->get('slogan', str($file)->headline()),
            'compose' => $compose,
            'tags' => $tags,
            'category' => $data->get('category'),
            'logo' => $data->get('logo', 'svgs/default.webp'),
            'minversion' => $data->get('minversion', '0.0.0'),
        ];

        if ($port = $data->get('port')) {
            $payload['port'] = $port;
        }

        if ($envFile = $data->get('env_file')) {
            $envFileContent = file_get_contents(base_path("templates/compose/$envFile"));
            $payload['envs'] = base64_encode($envFileContent);
        }

        return $payload;
    }

    private function generateServiceTemplatesWithFqdn(): void
    {
        $serviceTemplatesWithFqdn = collect(array_merge(
            glob(base_path('templates/compose/*.yaml')),
            glob(base_path('templates/compose/*.yml'))
        ))
            ->mapWithKeys(function ($file): array {
                $file = basename($file);
                $parsed = $this->processFileWithFqdn($file);

                return $parsed === false ? [] : [
                    Arr::pull($parsed, 'name') => $parsed,
                ];
            })->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents(base_path('templates/service-templates.json'), $serviceTemplatesWithFqdn.PHP_EOL);

        // Generate service-templates-raw.json with non-base64 encoded compose content
        // $this->generateServiceTemplatesRaw();
    }

    private function processFileWithFqdn(string $file): false|array
    {
        $content = file_get_contents(base_path("templates/compose/$file"));

        $data = collect(explode(PHP_EOL, $content))->mapWithKeys(function ($line): array {
            preg_match('/^#(?<key>.*):(?<value>.*)$/U', $line, $m);

            return $m ? [trim($m['key']) => trim($m['value'])] : [];
        });

        if (str($data->get('ignore'))->toBoolean()) {
            return false;
        }

        $documentation = $data->get('documentation');
        $documentation = $documentation ? $documentation.'?utm_source=coolify.io' : 'https://coolify.io/docs';

        // Replace SERVICE_URL with SERVICE_FQDN in the content
        $modifiedContent = str_replace('SERVICE_URL', 'SERVICE_FQDN', $content);

        $json = Yaml::parse($modifiedContent);
        $compose = base64_encode(Yaml::dump($json, 10, 2));

        $tags = str($data->get('tags'))->lower()->explode(',')->map(fn ($tag) => trim($tag))->filter();
        $tags = $tags->isEmpty() ? null : $tags->all();

        $payload = [
            'name' => pathinfo($file, PATHINFO_FILENAME),
            'documentation' => $documentation,
            'slogan' => $data->get('slogan', str($file)->headline()),
            'compose' => $compose,
            'tags' => $tags,
            'category' => $data->get('category'),
            'logo' => $data->get('logo', 'svgs/default.webp'),
            'minversion' => $data->get('minversion', '0.0.0'),
        ];

        if ($port = $data->get('port')) {
            $payload['port'] = $port;
        }

        if ($envFile = $data->get('env_file')) {
            $envFileContent = file_get_contents(base_path("templates/compose/$envFile"));
            // Also replace SERVICE_URL with SERVICE_FQDN in env file content
            $modifiedEnvContent = str_replace('SERVICE_URL', 'SERVICE_FQDN', $envFileContent);
            $payload['envs'] = base64_encode($modifiedEnvContent);
        }

        return $payload;
    }

    private function generateServiceTemplatesRaw(): void
    {
        $serviceTemplatesRaw = collect(array_merge(
            glob(base_path('templates/compose/*.yaml')),
            glob(base_path('templates/compose/*.yml'))
        ))
            ->mapWithKeys(function ($file): array {
                $file = basename($file);
                $parsed = $this->processFileWithFqdnRaw($file);

                return $parsed === false ? [] : [
                    Arr::pull($parsed, 'name') => $parsed,
                ];
            })->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents(base_path('templates/service-templates-raw.json'), $serviceTemplatesRaw.PHP_EOL);
    }

    private function processFileWithFqdnRaw(string $file): false|array
    {
        $content = file_get_contents(base_path("templates/compose/$file"));

        $data = collect(explode(PHP_EOL, $content))->mapWithKeys(function ($line): array {
            preg_match('/^#(?<key>.*):(?<value>.*)$/U', $line, $m);

            return $m ? [trim($m['key']) => trim($m['value'])] : [];
        });

        if (str($data->get('ignore'))->toBoolean()) {
            return false;
        }

        $documentation = $data->get('documentation');
        $documentation = $documentation ? $documentation.'?utm_source=coolify.io' : 'https://coolify.io/docs';

        // Replace SERVICE_URL with SERVICE_FQDN in the content
        $modifiedContent = str_replace('SERVICE_URL', 'SERVICE_FQDN', $content);

        $json = Yaml::parse($modifiedContent);
        $compose = Yaml::dump($json, 10, 2); // Not base64 encoded

        $tags = str($data->get('tags'))->lower()->explode(',')->map(fn ($tag) => trim($tag))->filter();
        $tags = $tags->isEmpty() ? null : $tags->all();

        $payload = [
            'name' => pathinfo($file, PATHINFO_FILENAME),
            'documentation' => $documentation,
            'slogan' => $data->get('slogan', str($file)->headline()),
            'compose' => $compose,
            'tags' => $tags,
            'category' => $data->get('category'),
            'logo' => $data->get('logo', 'svgs/default.webp'),
            'minversion' => $data->get('minversion', '0.0.0'),
        ];

        if ($port = $data->get('port')) {
            $payload['port'] = $port;
        }

        if ($envFile = $data->get('env_file')) {
            $envFileContent = file_get_contents(base_path("templates/compose/$envFile"));
            // Also replace SERVICE_URL with SERVICE_FQDN in env file content (not base64 encoded)
            $modifiedEnvContent = str_replace('SERVICE_URL', 'SERVICE_FQDN', $envFileContent);
            $payload['envs'] = $modifiedEnvContent;
        }

        return $payload;
    }
}
