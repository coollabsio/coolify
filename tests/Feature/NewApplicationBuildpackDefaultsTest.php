<?php

use App\Livewire\Project\New\GithubPrivateRepository;
use App\Livewire\Project\New\GithubPrivateRepositoryDeployKey;
use App\Livewire\Project\New\PublicGitRepository;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

describe('new application buildpack defaults', function () {
    test('github app repository flow defaults to nixpacks', function () {
        Livewire::test(GithubPrivateRepository::class, ['type' => 'private-gh-app'])
            ->assertSet('build_pack', 'nixpacks');
    });

    test('deploy key repository flow defaults to nixpacks', function () {
        Livewire::test(GithubPrivateRepositoryDeployKey::class, ['type' => 'private-deploy-key'])
            ->assertSet('build_pack', 'nixpacks');
    });

    test('public repository flow defaults to nixpacks and lists railpack second', function () {
        Livewire::test(PublicGitRepository::class, ['type' => 'public'])
            ->assertSet('build_pack', 'nixpacks');
    });

    test('public repository flow keeps railpack available after branch lookup', function () {
        Livewire::test(PublicGitRepository::class, ['type' => 'public'])
            ->set('branchFound', true)
            ->assertSeeInOrder(['Nixpacks', 'Railpack (Beta)']);
    });

    test('deploy key repository flow shows railpack beta label in build pack selector without beta badge', function () {
        Livewire::test(GithubPrivateRepositoryDeployKey::class, ['type' => 'private-deploy-key'])
            ->set('current_step', 'repository')
            ->assertSee('Railpack (Beta)');
    });
});
