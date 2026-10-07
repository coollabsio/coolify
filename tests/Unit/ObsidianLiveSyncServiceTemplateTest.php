<?php

it('includes an Obsidian LiveSync one-click service template', function () {
    $templatePath = __DIR__.'/../../templates/compose/obsidian-livesync.yaml';

    expect($templatePath)->toBeFile();

    $compose = file_get_contents($templatePath);

    expect($compose)
        ->toContain('image: couchdb:3.4.2')
        ->toContain('SERVICE_FQDN_COUCHDB_5984')
        ->toContain('COUCHDB_USER=${SERVICE_USER_COUCHDB}')
        ->toContain('COUCHDB_PASSWORD=${SERVICE_PASSWORD_64_COUCHDB}')
        ->toContain('MAX_DOCUMENT_SIZE=${MAX_DOCUMENT_SIZE:-52428800}')
        ->toContain('MAX_HTTP_REQUEST_SIZE=${MAX_HTTP_REQUEST_SIZE:-67108864}')
        ->toContain('source: ./local.ini')
        ->toContain('couchdb-data:/opt/couchdb/data')
        ->toContain('http://localhost:5984/_up');
});

it('ships the Obsidian LiveSync service icon', function () {
    expect(__DIR__.'/../../svgs/obsidian-livesync.svg')->toBeFile();
});
