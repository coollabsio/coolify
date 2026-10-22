<?php

use Symfony\Component\Yaml\Yaml;

it('wires the seafile template to a seadoc server for wikis and sdoc files', function () {
    $compose = Yaml::parseFile(__DIR__.'/../../templates/compose/seafile.yaml');
    $seafile = $compose['services']['seafile']['environment'];
    $seadoc = $compose['services']['seadoc']['environment'];

    expect($compose['services']['seadoc']['image'])->toStartWith('seafileltd/sdoc-server:')
        ->and($seadoc)->toContain('SERVICE_URL_SEADOC_80')
        ->and($seafile)->toContain('ENABLE_SEADOC=${ENABLE_SEADOC:-true}')
        // Seahub needs an absolute URL with a scheme, so SERVICE_FQDN_* (host only) does not work here.
        ->and($seafile)->toContain('SEADOC_SERVER_URL=${SERVICE_URL_SEADOC}')
        ->and($seadoc)->toContain('JWT_PRIVATE_KEY=${SERVICE_PASSWORD_64_JWT}')
        ->and($seafile)->toContain('JWT_PRIVATE_KEY=${SERVICE_PASSWORD_64_JWT}')
        ->and($seadoc)->toContain('DB_NAME=${SEAFILE_MYSQL_DB_SEAHUB_DB_NAME:-seahub_db}')
        ->and($seafile)->toContain('SEAFILE_MYSQL_DB_SEAHUB_DB_NAME=${SEAFILE_MYSQL_DB_SEAHUB_DB_NAME:-seahub_db}');

    $templates = json_decode(file_get_contents(__DIR__.'/../../templates/service-templates-latest.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(base64_decode($templates['seafile']['compose']))->toContain('SEADOC_SERVER_URL=${SERVICE_URL_SEADOC}');
});
