<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('lets unverified users access protected web routes without email delivery', function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $user = User::factory()->create(['email_verified_at' => null]);
    Team::query()->update(['show_boarding' => false]);
    Cache::flush();

    $this->actingAs($user)->get('/analytics')->assertOk();
});
