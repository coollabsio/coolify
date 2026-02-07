<?php

namespace App\Livewire\Project\Shared;

use App\Models\Server;
use Livewire\Component;
use Illuminate\Support\Collection;

class FileBrowser extends Component
{
    public Server $server;
    public string $containerName;
    public string $currentPath = '/';
    public Collection $files;
    public ?string $selectedFileContent = null;
    public ?string $selectedFileName = null;
    public bool $loading = false;

    public function mount()
    {
        $this->files = collect([]);
        $this->loadFiles();
    }

    public function loadFiles()
    {
        $this->loading = true;
        try {
            $command = "docker exec {$this->containerName} ls -Fp --group-directories-first {$this->currentPath}";
            $output = instant_remote_process([$command], $this->server, false);
            
            $this->files = collect(explode("\n", trim($output)))->filter()->map(function ($item) {
                $isDirectory = str_ends_with($item, '/');
                return [
                    'name' => rtrim($item, '/'),
                    'is_directory' => $isDirectory,
                    'path' => $this->currentPath === '/' ? "/{$item}" : "{$this->currentPath}/{$item}"
                ];
            });
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
        $this->loading = false;
    }

    public function changeDirectory(string $path)
    {
        $this->currentPath = rtrim($path, '/');
        if (empty($this->currentPath)) $this->currentPath = '/';
        $this->selectedFileContent = null;
        $this->loadFiles();
    }

    public function goUp()
    {
        if ($this->currentPath === '/') return;
        $parts = explode('/', rtrim($this->currentPath, '/'));
        array_pop($parts);
        $this->currentPath = implode('/', $parts);
        if (empty($this->currentPath)) $this->currentPath = '/';
        $this->loadFiles();
    }

    public function readFile(string $fileName)
    {
        $this->loading = true;
        $filePath = $this->currentPath === '/' ? "/{$fileName}" : "{$this->currentPath}/{$fileName}";
        try {
            $command = "docker exec {$this->containerName} cat {$filePath}";
            $this->selectedFileContent = instant_remote_process([$command], $this->server, false);
            $this->selectedFileName = $fileName;
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
        $this->loading = false;
    }

    public function render()
    {
        return view('livewire.project.shared.file-browser');
    }
}
