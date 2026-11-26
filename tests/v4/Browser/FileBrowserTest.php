<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack();
    $this->postgres = createBrowserPostgresql($this->stack, [
        'uuid' => 'db-browser-files',
        'name' => 'Files Postgres',
        'status' => 'exited',
    ]);
});

it('shows the Files tab and a stopped state for a stopped container', function () {
    loginAndSkipBoarding();

    $url = databaseConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->postgres
    ).'/files';

    $page = visit($url);

    $page->assertSee('Container is not running')
        ->assertSee('Start the container to browse its files')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'file-browser-stopped');
});

it('opens a directory whose name contains an apostrophe', function () {
    config(['constants.ssh.mux_enabled' => false]);
    Storage::fake('ssh-keys');
    $this->postgres->update(['status' => 'running']);
    Process::fake(function ($process) {
        $output = '';
        if (str_contains($process->command, 'docker inspect')) {
            $output = '/data';
        } elseif (str_contains($process->command, 'stat -c') && ! str_contains($process->command, 'folder')) {
            $output = "dir\t0\t1\t755\troot\troot\tit's a folder";
        }

        return Process::result(output: $output);
    });
    loginAndSkipBoarding();
    $url = databaseConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->postgres
    ).'/files';
    visit($url)
        ->assertSee("it's a folder")
        ->click("it's a folder")
        ->assertSee('This folder is empty')
        ->assertSee("it's a folder")
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'file-browser-apostrophe');
});

it('creates a file with the typed name from the New menu', function () {
    config(['constants.ssh.mux_enabled' => false]);
    Storage::fake('ssh-keys');
    $this->postgres->update(['status' => 'running']);
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result(output: str_contains($process->command, 'docker inspect') ? '/data' : '');
    });
    loginAndSkipBoarding();
    $url = databaseConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $this->postgres
    ).'/files';
    $page = visit($url)
        ->assertSee('This folder is empty')
        ->click('button:visible:has-text("New")')
        ->click('button:visible:has-text("New file")')
        ->fill('#newFileName', 'asd')
        ->click('button:visible:has-text("Create file")')
        ->assertSee('File created.')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'file-browser-create-file');

    expect(collect($commands)->contains(fn (string $command) => str_contains($command, '/data/asd')))->toBeTrue();
});
