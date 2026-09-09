<?php

use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds the template tracking columns to services', function () {
    expect(Schema::hasColumn('services', 'template_reference_hash'))->toBeTrue();
    expect(Schema::hasColumn('services', 'template_dismissed_hash'))->toBeTrue();
});

it('exposes the tracking columns as fillable', function () {
    $service = new Service;
    expect($service->getFillable())->toContain('template_reference_hash');
    expect($service->getFillable())->toContain('template_dismissed_hash');
});
