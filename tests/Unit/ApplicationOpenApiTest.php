<?php

use App\Models\Application;
use OpenApi\Attributes\Property;
use OpenApi\Attributes\Schema;

it('documents application settings under the settings property', function () {
    $schema = (new ReflectionClass(Application::class))
        ->getAttributes(Schema::class)[0]
        ->newInstance();

    $openApi = json_decode(
        file_get_contents(__DIR__.'/../../openapi.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $settingsProperty = collect($schema->properties)
        ->first(fn (mixed $property): bool => $property instanceof Property
            && $property->property === 'settings');

    expect($settingsProperty)
        ->not->toBeNull()
        ->and($openApi['components']['schemas']['Application']['properties'])
        ->toHaveKey('settings')
        ->not->toHaveKey('');
});
