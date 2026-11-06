<?php

namespace App\Livewire\Storage;

use App\Enums\BackupStorageType;
use App\Livewire\Storage\Concerns\SelectsSftpLogin;
use App\Models\S3Storage;
use App\Support\DomainUrlParts;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Uri;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;
    use SelectsSftpLogin;

    public string $type = 's3';

    public string $name;

    public string $description;

    public string $region = 'us-east-1';

    public string $key;

    public string $secret = '';

    public string $bucket = '';

    public string $endpoint = '';

    public bool $usePathStyleEndpoint = true;

    public bool $usePathStyleEndpointChanged = false;

    public bool $skipTlsVerification = false;

    public array $endpointParts = ['scheme' => 'https', 'host' => '', 'port' => '', 'path' => ''];

    public bool $endpointPartsChanged = false;

    /**
     * Host, port, and directory of an SFTP or FTPS storage.
     *
     * @var array{host: string, port: string, path: string}
     */
    public array $serverParts = ['host' => '', 'port' => '', 'path' => ''];

    public S3Storage $storage;

    protected function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(BackupStorageType::class)],
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            ...$this->withSftpLoginRules(S3Storage::connectionRules($this->storageType(), teamId: currentTeam()->id)),
            'usePathStyleEndpoint' => 'boolean',
            'skipTlsVerification' => 'boolean',
        ];
    }

    protected function messages(): array
    {
        if ($this->storageType() !== BackupStorageType::S3) {
            return array_merge(ValidationPatterns::combinedMessages(), [
                'key.regex' => 'The Username must not contain line breaks or quotes.',
                'privateKeyId.required' => 'Select the private key to log in with.',
            ]);
        }

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

    protected function validationAttributes(): array
    {
        $isS3 = $this->storageType() === BackupStorageType::S3;

        return [
            'name' => 'Name',
            'description' => 'Description',
            'region' => 'Region',
            'key' => $isS3 ? 'Key' : 'Username',
            'secret' => $isS3 ? 'Secret' : 'Password',
            'bucket' => 'Bucket',
            'endpoint' => $isS3 ? 'Endpoint' : 'Server',
            'usePathStyleEndpoint' => 'Use path-style URLs',
            'skipTlsVerification' => 'Skip TLS certificate verification',
            'privateKeyId' => 'Private key',
        ];
    }

    public function submit()
    {
        try {
            $this->authorize('create', S3Storage::class);

            $type = $this->storageType();
            if ($type === BackupStorageType::Sftp || $type === BackupStorageType::Ftps) {
                $this->endpoint = S3Storage::serverEndpoint($type, ...$this->serverParts);
            } else {
                if ($this->endpointPartsChanged) {
                    $this->endpoint = DomainUrlParts::compose(...$this->endpointParts);
                }
                $this->endpoint = $this->normalizeEndpoint($this->endpoint);
            }
            $this->validate();
            $this->storage = new S3Storage;
            $this->storage->type = $type;
            if ($type !== BackupStorageType::S3) {
                $this->bucket = '';
                $this->region = 'us-east-1';
                $this->skipTlsVerification = false;
            }
            $this->storage->name = $this->name;
            $this->storage->description = $this->description ?? null;
            $this->storage->region = $this->region;
            $this->storage->key = $this->key;
            $this->storage->private_key_id = $type === BackupStorageType::Sftp ? $this->selectedPrivateKeyId() : null;
            $this->storage->secret = $this->storage->private_key_id ? '' : $this->secret;
            $this->storage->bucket = $this->bucket;
            $this->storage->use_path_style_endpoint = $this->usePathStyleEndpoint;
            $this->storage->skip_tls_verification = $this->skipTlsVerification;
            if (empty($this->endpoint)) {
                $this->storage->endpoint = "https://s3.{$this->region}.amazonaws.com";
            } else {
                $this->storage->endpoint = $this->endpoint;
            }
            $this->storage->team_id = currentTeam()->id;
            $this->storage->testConnection(acceptNewHostKey: true);
            $this->storage->save();

            return redirectRoute($this, 'storage.show', [$this->storage->uuid]);
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to create storage.', $this->connectionErrorDescription($e));
            // return handleError($e, $this);
        }
    }

    public function render(): View
    {
        $teamId = currentTeam()?->id;
        $listsPrivateKeys = $teamId !== null && $this->type === BackupStorageType::Sftp->value;

        return view('livewire.storage.create', [
            'privateKeys' => $listsPrivateKeys ? $this->privateKeyOptions($teamId) : [],
            'publicKey' => $listsPrivateKeys ? $this->selectedPublicKey($teamId) : null,
        ]);
    }

    public function storageType(): BackupStorageType
    {
        return BackupStorageType::tryFrom($this->type) ?? BackupStorageType::S3;
    }

    public function updatedType(): void
    {
        $this->resetValidation();
    }

    public function updatedEndpointParts(): void
    {
        $this->endpointPartsChanged = true;
        if (! $this->usePathStyleEndpointChanged) {
            $this->usePathStyleEndpoint = S3Storage::detectPathStyleEndpoint(DomainUrlParts::compose(...$this->endpointParts));
        }
    }

    public function updatedUsePathStyleEndpoint(): void
    {
        $this->usePathStyleEndpointChanged = true;
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
