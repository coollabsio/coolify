<?php

use Symfony\Component\Yaml\Yaml;

it('keeps the wordpress openlitespeed mariadb template parseable and internally wired', function () {
    $templatePath = base_path('templates/compose/wordpress-with-openlitespeed-mariadb.yaml');

    expect(is_file($templatePath))->toBeTrue();

    $content = file_get_contents($templatePath);
    expect($content)->toContain('# category: cms')
        ->toContain('# logo: svgs/wordpress.svg')
        ->toContain('openlitespeed');

    $compose = Yaml::parse($content);

    expect($compose)->toBeArray()
        ->and($compose)->toHaveKey('services');

    $services = $compose['services'];
    expect(array_keys($services))->toContain('wordpress', 'mariadb');

    $wordpress = $services['wordpress'];
    expect($wordpress['depends_on'] ?? [])->toContain('mariadb');

    $wordpressEnvironment = $wordpress['environment'] ?? [];
    expect($wordpressEnvironment)->toContain(
        'WORDPRESS_DB_HOST=mariadb',
        'WORDPRESS_DB_NAME=wordpress',
        'WORDPRESS_DB_USER=$SERVICE_USER_WORDPRESS',
        'WORDPRESS_DB_PASSWORD=$SERVICE_PASSWORD_WORDPRESS'
    );

    $wordpressCommand = $wordpress['command'] ?? '';
    expect($wordpressCommand)->toContain('wp config create')
        ->toContain('exec /entrypoint.sh');

    $mariadbEnvironment = $services['mariadb']['environment'] ?? [];
    expect($mariadbEnvironment)->toContain(
        'MYSQL_DATABASE=wordpress',
        'MYSQL_USER=$SERVICE_USER_WORDPRESS',
        'MYSQL_PASSWORD=$SERVICE_PASSWORD_WORDPRESS'
    );
});
