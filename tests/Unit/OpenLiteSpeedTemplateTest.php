<?php

it('ships an OpenLiteSpeed service template with persistent server data', function () {
    $template = file_get_contents(base_path('templates/compose/openlitespeed.yaml'));

    expect($template)
        ->toContain('litespeedtech/openlitespeed')
        ->toContain('SERVICE_URL_OPENLITESPEED_80')
        ->toContain('openlitespeed-conf:/usr/local/lsws/conf')
        ->toContain('openlitespeed-admin-conf:/usr/local/lsws/admin/conf')
        ->toContain('openlitespeed-sites:/var/www/vhosts')
        ->toContain('openlitespeed-logs:/usr/local/lsws/logs');
});
