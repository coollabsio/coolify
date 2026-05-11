<?php

it('ships a WordPress OpenLiteSpeed service template with the requested stack', function () {
    $template = file_get_contents(base_path('templates/compose/wordpress-with-openlitespeed.yaml'));

    expect($template)
        ->toContain('litespeedtech/openlitespeed')
        ->toContain('SERVICE_URL_WORDPRESS_80')
        ->toContain('wp core download')
        ->toContain('mariadb:11.4')
        ->toContain('redis:alpine')
        ->toContain('phpmyadmin/phpmyadmin')
        ->toContain('wordpress-sites:/var/www/vhosts')
        ->toContain('mariadb-data:/var/lib/mysql');
});
