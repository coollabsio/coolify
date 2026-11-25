<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('resourceable columns have one composite unique index', function () {
    $resourceableIndexes = collect(Schema::getIndexes('secret_manager_links'))
        ->filter(fn (array $index): bool => $index['columns'] === ['resourceable_type', 'resourceable_id'])
        ->values();

    expect($resourceableIndexes)
        ->toHaveCount(1)
        ->and($resourceableIndexes->first()['unique'])->toBeTrue();
});
