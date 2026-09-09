<?php

use App\Actions\Database\StopDatabase;
use App\Models\BaseModel;

it('declares strict method types', function () {
    $handle = new ReflectionMethod(StopDatabase::class, 'handle');
    $stopContainer = new ReflectionMethod(StopDatabase::class, 'stopContainer');

    expect($handle->getReturnType()?->getName())->toBe('string')
        ->and($stopContainer->getParameters()[0]->getType()?->getName())->toBe(BaseModel::class);
});
