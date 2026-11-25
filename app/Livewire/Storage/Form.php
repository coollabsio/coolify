<?php

namespace App\Livewire\Storage;

use App\Enums\BackupStorageType;
use App\Livewire\Storage\Concerns\SelectsSftpLogin;
use App\Models\S3Storage;
use App\Support\DomainUrlParts;
use App\Support\ValidationPatterns;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

class Form extends Component
{
    use AuthorizesRequests;
    use SelectsSftpLogin;

    public S3Storage $storage;

    // Explicit properties
    public ?string $name = null;

    public ?string $description = null;

    public string $endpoint;

    public array $endpointParts = ['scheme' => 'https', 'host' => '', 'port' => '', 'path' => ''];

    public bool $endpointPartsChanged = false;

    /**
     * Host, port, and directory of an SFTP or FTPS storage.
     *
     * @var array{host: string, port: string, path: string}
     */
    public array $serverParts = ['host' => '', 'port' => '', 'path' => ''];

    public ?string $bucket = null;

    public string $region;

    public bool $usePathStyleEndpoint = true;

    public bool $usePathStyleEndpointChanged = false;

    public bool $skipTlsVerification = false;

    public string $key;

    /**
     * A new secret key or password. The saved one never goes to the browser; empty keeps it.
     */
    public string $secret = '';

    public bool $hasSavedSecret = false;

    public ?bool $isUsable = null;

    public bool $isPasswordHiddenForMember = false;

    protected function rules(): array
    {
        $connectionRules = $this->withSftpLoginRules(S3Storage::connectionRules($this->storage->type, trustedInternalHosts: $this->storage->trustedInternalHosts(), teamId: $this->storage->team_id, savedEndpoint: $this->storage->getOriginal('endpoint')));
        if ($this->hasSavedSecret) {
            $connectionRules['secret'] = ['nullable', 'string', 'max:255'];
        }

        return [
            'isUsable' => 'nullable|boolean',
            'name' => ValidationPatterns::nameRules(required: false),
            'description' => ValidationPatterns::descriptionRules(),
            ...$connectionRules,
            'usePathStyleEndpoint' => 'boolean',
            'skipTlsVerification' => 'boolean',
        ];
    }

    /**
     * The secret to save or test: the new one from the form, else the saved one.
     */
    private function secretToUse(): string
    {
        return filled($this->secret) ? $this->secret : (string) $this->storage->secret;
    }

    protected function messages(): array
    {
        if (! $this->storage->isS3()) {
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
        $isS3 = $this->storage->isS3();

        return [
            'isUsable' => 'Is Usable',
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

    /**
     * Builds the endpoint from the form fields of the storage type.
     */
    private function composeEndpoint(): void
    {
        $type = $this->storage->type;
        if ($type === BackupStorageType::Sftp || $type === BackupStorageType::Ftps) {
            $this->endpoint = S3Storage::serverEndpoint($type, ...$this->serverParts);
        } elseif ($this->endpointPartsChanged) {
            $this->endpoint = DomainUrlParts::compose(...$this->endpointParts);
        }
    }

    /**
     * Sync data between component properties and model
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            // Sync TO model (before save)
            $this->storage->name = $this->name;
            $this->storage->description = $this->description;
            $this->storage->endpoint = $this->endpoint;
            $this->storage->bucket = $this->bucket;
            $this->storage->region = $this->region;
            $this->storage->use_path_style_endpoint = $this->usePathStyleEndpoint;
            $this->storage->skip_tls_verification = $this->storage->isS3() && $this->skipTlsVerification;
            $this->storage->key = $this->key;
            $this->storage->private_key_id = $this->storage->type === BackupStorageType::Sftp ? $this->selectedPrivateKeyId() : null;
            $this->storage->secret = $this->storage->private_key_id ? '' : $this->secretToUse();
            $this->storage->is_usable = $this->isUsable;
        } else {
            // Sync FROM model (on load/refresh)
            $this->name = $this->storage->name;
            $this->description = $this->storage->description;
            $this->endpoint = $this->storage->endpoint;
            $this->endpointParts = DomainUrlParts::split($this->endpoint);
            $this->endpointPartsChanged = false;
            $this->serverParts = S3Storage::splitServerEndpoint($this->endpoint);
            $this->bucket = $this->storage->bucket;
            $this->region = $this->storage->region;
            $this->usePathStyleEndpoint = $this->storage->usesPathStyleEndpoint();
            // A saved style that detection would not pick is a user choice, so keep it when the endpoint changes.
            $this->usePathStyleEndpointChanged = $this->usePathStyleEndpoint !== S3Storage::detectPathStyleEndpoint($this->endpoint);
            $this->skipTlsVerification = (bool) $this->storage->skip_tls_verification;
            $canSeeCredentials = auth()->user()?->can('update', $this->storage) ?? false;
            $this->key = $canSeeCredentials ? $this->storage->key : '';
            $this->secret = '';
            $this->hasSavedSecret = $canSeeCredentials && filled($this->storage->secret);
            $this->privateKeyId = $this->storage->private_key_id;
            $this->sftpAuth = $this->privateKeyId ? 'key' : 'password';
            $this->isUsable = $this->storage->is_usable;
        }
    }

    public function render(): View
    {
        $isSftp = $this->storage->type === BackupStorageType::Sftp;

        return view('livewire.storage.form', [
            'privateKeys' => $isSftp ? $this->privateKeyOptions($this->storage->team_id) : [],
            'publicKey' => $isSftp ? $this->selectedPublicKey($this->storage->team_id) : null,
        ]);
    }

    public function mount()
    {
        $this->syncData(false);

        $this->isPasswordHiddenForMember = ! (auth()->user()?->can('update', $this->storage) ?? false);
    }

    public function testConnection()
    {
        $testedStorage = null;

        try {
            $this->authorize('validateConnection', $this->storage);
            $this->composeEndpoint();
            $testedStorage = new S3Storage;
            $testedStorage->type = $this->storage->type;
            $testedStorage->uuid = $this->storage->uuid;
            $testedStorage->team_id = $this->storage->team_id;
            $testedStorage->unusable_email_sent = $this->storage->unusable_email_sent;
            $testedStorage->name = $this->name;
            $testedStorage->description = $this->description;
            // The saved host key applies while the host and the port stay the same; the endpoint setter removes it otherwise.
            $testedStorage->endpoint = $this->storage->endpoint;
            $testedStorage->sftp_host_key = $this->storage->sftp_host_key;
            $testedStorage->endpoint = $this->endpoint;
            $testedStorage->bucket = $this->bucket;
            $testedStorage->region = $this->region;
            $testedStorage->use_path_style_endpoint = $this->usePathStyleEndpoint;
            $testedStorage->skip_tls_verification = $testedStorage->isS3() && $this->skipTlsVerification;
            $testedStorage->key = $this->key;
            $testedStorage->private_key_id = $testedStorage->type === BackupStorageType::Sftp ? $this->selectedPrivateKeyId() : null;
            $testedStorage->secret = $testedStorage->private_key_id ? '' : $this->secretToUse();

            $testedStorage->testConnection(acceptNewHostKey: true);
            $switchedToVirtualHostedStyle = $this->usePathStyleEndpoint && ! $testedStorage->usesPathStyleEndpoint();
            $this->usePathStyleEndpoint = $testedStorage->usesPathStyleEndpoint();

            // Update component property to reflect the new validation status
            $this->isUsable = $testedStorage->is_usable;
            $this->storage->is_usable = $testedStorage->is_usable;
            $this->storage->unusable_email_sent = $testedStorage->unusable_email_sent;
            if ($testedStorage->endpoint === $this->storage->endpoint) {
                $this->storage->sftp_host_key = $testedStorage->sftp_host_key;
            }
            $this->storage->save();
            $this->dispatch('storage-status-changed', isUsable: $this->isUsable);

            return $this->dispatch('success', 'Connection is working.', match (true) {
                $switchedToVirtualHostedStyle => 'The provider rejects path-style URLs, so "Use path-style URLs" is now off. Save to keep it.',
                $testedStorage->isS3() => 'Tested with "ListObjectsV2" action.',
                default => 'Tested by listing the backup directory.',
            });
        } catch (\Throwable $e) {
            if ($testedStorage) {
                $this->isUsable = $testedStorage->is_usable;
                $this->storage->is_usable = $testedStorage->is_usable;
                $this->storage->unusable_email_sent = $testedStorage->unusable_email_sent;
                $this->storage->save();
            }
            $this->dispatch('storage-status-changed', isUsable: $this->isUsable);

            $this->dispatch('error', 'Failed to test connection.', $e->getMessage());
        }
    }

    #[On('submitStorage')]
    public function submit()
    {
        try {
            $this->authorize('update', $this->storage);
            $this->composeEndpoint();

            DB::transaction(function () {
                $this->validate();

                // Sync properties to model before saving
                $this->syncData(true);
                $this->storage->save();

                // Test connection with new values - if this fails, transaction will rollback
                $this->storage->testConnection(shouldSave: false, acceptNewHostKey: true);

                // If we get here, the connection test succeeded
                $this->storage->is_usable = true;
                $this->storage->unusable_email_sent = false;
                $this->storage->save();

                // Update local properties to reflect success
                $this->isUsable = true;
                $this->secret = '';
                $this->hasSavedSecret = filled($this->storage->secret);
                $this->usePathStyleEndpoint = $this->storage->usesPathStyleEndpoint();
            });
            $this->dispatch('name-changed', uuid: $this->storage->uuid, name: $this->storage->name);

            $this->dispatch('success', 'Storage settings updated and connection verified.');
        } catch (\Throwable $e) {
            // Refresh the model to revert UI to database values after rollback
            $this->storage->refresh();
            $this->syncData(false);

            return handleError($e, $this);
        }
    }

    /**
     * Removes the saved SFTP host key, for a server whose key changed on purpose. The next connection test that an admin
     * starts (Validate) saves the key that the server presents then; backups do not.
     */
    public function resetHostKey(): void
    {
        $this->authorize('update', $this->storage);

        $this->storage->forceFill(['sftp_host_key' => null, 'is_usable' => false])->save();
        $this->isUsable = false;
        $this->dispatch('storage-status-changed', isUsable: false);
        $this->dispatch('success', 'Host key removed.', 'Validate the connection to save the current host key of the server.');
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
}
