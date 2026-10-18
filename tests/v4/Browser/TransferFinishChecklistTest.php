<?php

use App\Models\Server;
use App\Models\Team;
use App\Services\ServerTransfer\TransferFinishChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    $this->stack = seedBrowserResourceStack();
    $this->server = Server::create(['name' => 'transferred-web', 'ip' => '10.0.0.5', 'team_id' => 0, 'private_key_id' => 1]);
    $this->server->forceFill(['uuid' => 'transferred-1'])->save();
    createBrowserApplication([...$this->stack, 'destination' => $this->server->standaloneDockers()->first()], [
        'name' => 'shop',
        'build_pack' => 'nixpacks',
        'git_repository' => 'https://github.com/acme/shop',
    ]);
    app(TransferFinishChecklist::class)->start($this->server);
});

it('shows the finish list on the server page and finishes it after a confirmation', function () {
    Team::query()->update(['show_boarding' => false]);
    Cache::flush();
    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard');

    $page->navigate('/server/transferred-1')
        ->assertSee('Finish the transfer')
        ->assertSee('Manual webhook of shop')
        ->screenshot(filename: 'transfer-finish-list');

    $page->click('button:visible:has-text("I finished")')
        ->wait(1)
        ->assertSee('Finish the transfer?')
        ->assertSee('Skipped: Manual webhook of shop')
        ->screenshot(filename: 'transfer-finish-confirm');
});
