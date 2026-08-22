<?php

namespace App\Livewire\Server;

use App\Models\Server;
use App\Services\ServerTransfer\ServerTransferBundle;
use App\Services\ServerTransfer\ServerTransferImporter;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class TransferImport extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public string $bundleJson = '';

    public string $passphrase = '';

    public bool $preserveUuids = true;

    public bool $adoptMode = true;

    /** Write ownership file on the host via SSH when claiming after import. */
    public bool $writeRemote = false;

    /** @var TemporaryUploadedFile|null */
    public $bundleFile = null;

    /** @var array<string, mixed>|null */
    public ?array $lastResult = null;

    /** @var list<string> */
    public array $lastWarnings = [];

    public ?string $importedServerUuid = null;

    public function mount(): void
    {
        $this->ensureDevelopmentAvailability();
        $this->authorize('create', Server::class);
    }

    public function updatedBundleFile(): void
    {
        $this->ensureDevelopmentAvailability();

        if (! $this->bundleFile) {
            return;
        }
        try {
            $contents = $this->bundleFile->get();
            if (! is_string($contents) || blank($contents)) {
                throw new \RuntimeException('Uploaded file is empty.');
            }
            $this->bundleJson = $contents;
            $this->dispatch('success', 'Bundle file loaded into the form.');
        } catch (Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveBundle(): array
    {
        $raw = trim($this->bundleJson);
        if ($raw === '') {
            throw new \RuntimeException('Paste a transfer bundle JSON or upload a file.');
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Bundle is not valid JSON.');
        }

        if (data_get($decoded, 'encrypted')) {
            if (blank($this->passphrase)) {
                throw new \RuntimeException('Passphrase is required for encrypted bundles.');
            }

            return ServerTransferBundle::decryptWithPassphrase($decoded, $this->passphrase);
        }

        return $decoded;
    }

    public function dryRun(ServerTransferImporter $importer): void
    {
        $this->ensureDevelopmentAvailability();
        $this->runImport($importer, dryRun: true);
    }

    public function importBundle(ServerTransferImporter $importer): void
    {
        $this->ensureDevelopmentAvailability();
        $this->runImport($importer, dryRun: false);
    }

    private function runImport(ServerTransferImporter $importer, bool $dryRun): void
    {
        try {
            $this->authorize('create', Server::class);
            $teamId = currentTeam()->id;
            $bundle = $this->resolveBundle();

            $result = $importer->import(
                bundle: $bundle,
                teamId: $teamId,
                dryRun: $dryRun,
                preserveUuids: $this->preserveUuids,
                adoptMode: $this->adoptMode,
                claim: ! $dryRun,
                writeRemote: $this->writeRemote,
                rebindSentinel: true,
            );

            $this->lastResult = $result;
            $this->lastWarnings = array_values((array) data_get($result, 'warnings', []));
            $this->importedServerUuid = $dryRun ? null : data_get($result, 'server_uuid');

            if ($dryRun) {
                $this->dispatch('success', 'Dry run completed — nothing was written.');
            } elseif (data_get($result, 'claimed')) {
                $this->dispatch('success', 'Server imported and claimed for this instance.');
            } else {
                $this->dispatch('success', 'Server imported. Claim did not complete — check warnings or re-claim from the server Transfer page.');
            }
        } catch (Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.transfer-import');
    }

    private function ensureDevelopmentAvailability(): void
    {
        abort_unless(isDev(), 404);
    }
}
