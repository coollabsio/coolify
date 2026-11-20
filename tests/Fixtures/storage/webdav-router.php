<?php

/**
 * Router for `php -S` in tests/Unit/WebdavFilesystemAdapterTest.php: a WebDAV server that serves fixed files, stalls,
 * or sends more data than it announced.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && $path === '/backup.sql') {
    echo 'backup contents';

    return true;
}

if ($method === 'GET' && $path === '/large.sql') {
    echo str_repeat('x', 100);

    return true;
}

if ($path === '/stall.sql') {
    if ($method === 'GET') {
        header('Content-Length: 1000');
        echo 'abc';
        flush();
    }
    sleep(30);

    return true;
}

http_response_code(404);
echo 'not found page';

return true;
