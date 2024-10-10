<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Symfony\Component\Yaml\Yaml;

class ServicesGenerate extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $signature = 'services:generate';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Generate service-templates.yaml based on /templates/compose directory';

    public function handle(): int
    {
        $serviceTemplatesJson = collect(glob(base_path('templates/compose/*.yaml')))
            ->mapWithKeys(function ($file): array {
                $file = basename($file);
                $parsed = $this->processFile($file);

                return $parsed === false ? [] : [
                    Arr::pull($parsed, 'name') => $parsed,
                ];
            })->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        file_put_contents(base_path('templates/service-templates.json'), $serviceTemplatesJson.PHP_EOL);

        return self::SUCCESS;
    }

    private function processFile(string $file): false|array
    {
        $content = file_get_contents(base_path("templates/compose/$file"));

        preg_match_all(
            '/#\s*(documentation|env_file|ignore|logo|minversion|port|slogan|tags)\s*:\s*(.+)\s*/',
            $content, $matches
        );

        $data = array_combine($matches[1], $matches[2]);

        if (str($data['ignore'] ?? false)->toBoolean()) {
            $this->info("Ignoring $file");

            return false;
        }

        $this->info("Processing $file");

        $documentation = $data['documentation'] ?? null;
        $documentation = $documentation ? $documentation.'?utm_source=coolify.io' : 'https://coolify.io/docs';

        $json = Yaml::parse($content);
        $compose = base64_encode(Yaml::dump($json, 10, 2));

        $tags = str($data['tags'] ?? '')->lower()->explode(',')->map(fn ($tag) => trim($tag))->filter();
        $tags = $tags->isEmpty() ? null : $tags->all();

        $payload = [
            'name' => pathinfo($file, PATHINFO_FILENAME),
            'documentation' => $documentation,
            'slogan' => $data['slogan'] ?? str($file)->headline(),
            'compose' => $compose,
            'tags' => $tags,
            'logo' => $data['logo'] ?? 'svgs/coolify.png',
            'minversion' => $data['minversion'] ?? '0.0.0',
        ];

        if ($port = $data['port'] ?? null) {
            $payload['port'] = $port;
        }

        if ($envFile = $data['env_file'] ?? null) {
            $envFileContent = file_get_contents(base_path("templates/compose/$envFile"));
            $payload['envs'] = base64_encode($envFileContent);
        }

        return $payload;
    }
}
