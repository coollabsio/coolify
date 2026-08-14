<?php

namespace App\Livewire\Storage;

use App\Models\S3Storage;
use App\Rules\SafeWebhookUrl;
use App\Rules\ValidS3BucketName;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Uri;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    public string $name;

    public string $description;

    public string $region = 'us-east-1';

    public string $key;

    public string $secret;

    public string $bucket;

    public string $endpoint;

    public S3Storage $storage;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'region' => 'required|max:255',
            'key' => 'required|max:255',
            'secret' => 'required|max:255',
            'bucket' => ['required', new ValidS3BucketName],
            'endpoint' => ['required', 'max:255', new SafeWebhookUrl],
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'region.required' => 'The Region field is required.',
                'region.max' => 'The Region may not be greater than 255 characters.',
                'key.required' => 'The Access Key field is required.',
                'key.max' => 'The Access Key may not be greater than 255 characters.',
                'secret.required' => 'The Secret Key field is required.',
                'secret.max' => 'The Secret Key may not be greater than 255 characters.',
                'bucket.required' => 'The Bucket field is required.',
                'endpoint.required' => 'The Endpoint field is required.',
                'endpoint.max' => 'The Endpoint may not be greater than 255 characters.',
            ]
        );
    }

    protected $validationAttributes = [
        'name' => 'Name',
        'description' => 'Description',
        'region' => 'Region',
        'key' => 'Key',
        'secret' => 'Secret',
        'bucket' => 'Bucket',
        'endpoint' => 'Endpoint',
    ];

    public function submit()
    {
        try {
            $this->authorize('create', S3Storage::class);

            $this->endpoint = $this->normalizeEndpoint($this->endpoint);
            $this->validate();
            $this->storage = new S3Storage;
            $this->storage->name = $this->name;
            $this->storage->description = $this->description ?? null;
            $this->storage->region = $this->region;
            $this->storage->key = $this->key;
            $this->storage->secret = $this->secret;
            $this->storage->bucket = $this->bucket;
            if (empty($this->endpoint)) {
                $this->storage->endpoint = "https://s3.{$this->region}.amazonaws.com";
            } else {
                $this->storage->endpoint = $this->endpoint;
            }
            $this->storage->team_id = currentTeam()->id;
            $this->storage->testConnection();
            $this->storage->save();

            return redirectRoute($this, 'storage.show', [$this->storage->uuid]);
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to create storage.', $this->connectionErrorDescription($e));
            // return handleError($e, $this);
        }
    }

    private function connectionErrorDescription(\Throwable $exception): string
    {
        $settingsUrl = route('settings.advanced').'#endpoint-section';
        $description = e($exception->getMessage());

        if (! str_contains($exception->getMessage(), $settingsUrl)) {
            return $description;
        }

        $link = '<a class="font-medium underline" href="'.e($settingsUrl).'">Set them here.</a>';

        return str_replace(e($settingsUrl), $link, $description);
    }

    private function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);

        $hasScheme = preg_match('/^(?:https?:|[a-z][a-z0-9+.-]*:\/\/)/i', $endpoint) === 1;
        if (! $hasScheme) {
            $endpoint = 'https://'.$endpoint;
        }

        if (str($endpoint)->contains('digitaloceanspaces.com')) {
            $host = Uri::of($endpoint)->host();

            if (preg_match('/^(.+)\.([^.]+\.digitaloceanspaces\.com)$/', $host, $matches)) {
                return "https://{$matches[2]}";
            }
        }

        return $endpoint;
    }
}
