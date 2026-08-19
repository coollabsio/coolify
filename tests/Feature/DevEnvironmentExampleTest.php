<?php

it('runs the normal development database seeder without v5 seeders', function () {
    $databaseSeeder = file_get_contents(database_path('seeders/DatabaseSeeder.php'));
    $developmentSeederBlock = str($databaseSeeder)->after("if (in_array(config('app.env'), ['local', 'development', 'dev'], true)) {")->before('        }')->toString();

    expect($developmentSeederBlock)->toContain('DevelopmentRailpackExamplesSeeder::class')
        ->and($developmentSeederBlock)->not->toContain('V5DevLimaSeeder::class');
});
