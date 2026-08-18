<?php

test('parseEnvFormatToArray parses simple KEY=VALUE pairs', function () {
    $input = "KEY1=value1\nKEY2=value2";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'KEY1' => ['value' => 'value1', 'comment' => null],
        'KEY2' => ['value' => 'value2', 'comment' => null],
    ]);
});

test('parseEnvFormatToArray strips inline comments from unquoted values', function () {
    $input = "NIXPACKS_NODE_VERSION=22 #needed for now\nNODE_VERSION=22";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'NIXPACKS_NODE_VERSION' => ['value' => '22', 'comment' => 'needed for now'],
        'NODE_VERSION' => ['value' => '22', 'comment' => null],
    ]);
});

test('parseEnvFormatToArray strips inline comments only when preceded by whitespace', function () {
    $input = "KEY1=value1#nocomment\nKEY2=value2 #comment\nKEY3=value3  # comment with spaces";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'KEY1' => ['value' => 'value1#nocomment', 'comment' => null],
        'KEY2' => ['value' => 'value2', 'comment' => 'comment'],
        'KEY3' => ['value' => 'value3', 'comment' => 'comment with spaces'],
    ]);
});

test('parseEnvFormatToArray preserves # in quoted values', function () {
    $input = "KEY1=\"value with # hash\"\nKEY2='another # hash'";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'KEY1' => ['value' => 'value with # hash', 'comment' => null],
        'KEY2' => ['value' => 'another # hash', 'comment' => null],
    ]);
});

test('parseEnvFormatToArray handles quoted values correctly', function () {
    $input = "KEY1=\"quoted value\"\nKEY2='single quoted'";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'KEY1' => ['value' => 'quoted value', 'comment' => null],
        'KEY2' => ['value' => 'single quoted', 'comment' => null],
    ]);
});

test('parseEnvFormatToArray skips comment lines', function () {
    $input = "# This is a comment\nKEY1=value1\n# Another comment\nKEY2=value2";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'KEY1' => ['value' => 'value1', 'comment' => null],
        'KEY2' => ['value' => 'value2', 'comment' => null],
    ]);
});

test('parseEnvFormatToArray skips empty lines', function () {
    $input = "KEY1=value1\n\nKEY2=value2\n\n";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'KEY1' => ['value' => 'value1', 'comment' => null],
        'KEY2' => ['value' => 'value2', 'comment' => null],
    ]);
});

test('parseEnvFormatToArray handles values with equals signs', function () {
    $input = 'KEY1=value=with=equals';
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'KEY1' => ['value' => 'value=with=equals', 'comment' => null],
    ]);
});

test('parseEnvFormatToArray handles empty values', function () {
    $input = "KEY1=\nKEY2=value";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'KEY1' => ['value' => '', 'comment' => null],
        'KEY2' => ['value' => 'value', 'comment' => null],
    ]);
});

test('parseEnvFormatToArray handles complex real-world example', function () {
    $input = <<<'ENV'
# Database Configuration
DB_HOST=localhost
DB_PORT=5432 #default postgres port
DB_NAME="my_database"
DB_PASSWORD='p@ssw0rd#123'

# API Keys
API_KEY=abc123 # Production key
SECRET_KEY=xyz789
ENV;

    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'DB_HOST' => ['value' => 'localhost', 'comment' => null],
        'DB_PORT' => ['value' => '5432', 'comment' => 'default postgres port'],
        'DB_NAME' => ['value' => 'my_database', 'comment' => null],
        'DB_PASSWORD' => ['value' => 'p@ssw0rd#123', 'comment' => null],
        'API_KEY' => ['value' => 'abc123', 'comment' => 'Production key'],
        'SECRET_KEY' => ['value' => 'xyz789', 'comment' => null],
    ]);
});

test('parseEnvFormatToArray handles the original bug scenario', function () {
    $input = "NIXPACKS_NODE_VERSION=22 #needed for now\nNODE_VERSION=22";
    $result = parseEnvFormatToArray($input);

    // The value should be "22", not "22 #needed for now"
    expect($result['NIXPACKS_NODE_VERSION']['value'])->toBe('22');
    expect($result['NIXPACKS_NODE_VERSION']['value'])->not->toContain('#');
    expect($result['NIXPACKS_NODE_VERSION']['value'])->not->toContain('needed');
    // And the comment should be extracted
    expect($result['NIXPACKS_NODE_VERSION']['comment'])->toBe('needed for now');
});

test('parseEnvFormatToArray handles quoted strings with spaces before hash', function () {
    $input = "KEY1=\"value with spaces\" #comment\nKEY2=\"another value\"";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'KEY1' => ['value' => 'value with spaces', 'comment' => 'comment'],
        'KEY2' => ['value' => 'another value', 'comment' => null],
    ]);
});

test('parseEnvFormatToArray handles unquoted values with multiple hash symbols', function () {
    $input = "KEY1=value1#not#comment\nKEY2=value2 # comment # with # hashes";
    $result = parseEnvFormatToArray($input);

    // KEY1: no space before #, so entire value is kept
    // KEY2: space before first #, so everything from first space+# is stripped
    expect($result)->toBe([
        'KEY1' => ['value' => 'value1#not#comment', 'comment' => null],
        'KEY2' => ['value' => 'value2', 'comment' => 'comment # with # hashes'],
    ]);
});

test('parseEnvFormatToArray handles quoted values containing hash symbols at various positions', function () {
    $input = "KEY1=\"#starts with hash\"\nKEY2=\"hash # in middle\"\nKEY3=\"ends with hash#\"";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'KEY1' => ['value' => '#starts with hash', 'comment' => null],
        'KEY2' => ['value' => 'hash # in middle', 'comment' => null],
        'KEY3' => ['value' => 'ends with hash#', 'comment' => null],
    ]);
});

test('parseEnvFormatToArray trims whitespace before comments', function () {
    $input = "KEY1=value1   #comment\nKEY2=value2\t#comment with tab";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'KEY1' => ['value' => 'value1', 'comment' => 'comment'],
        'KEY2' => ['value' => 'value2', 'comment' => 'comment with tab'],
    ]);
    // Values should not have trailing spaces
    expect($result['KEY1']['value'])->not->toEndWith(' ');
    expect($result['KEY2']['value'])->not->toEndWith("\t");
});

test('parseEnvFormatToArray preserves hash in passwords without spaces', function () {
    $input = "PASSWORD=pass#word123\nAPI_KEY=abc#def#ghi";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'PASSWORD' => ['value' => 'pass#word123', 'comment' => null],
        'API_KEY' => ['value' => 'abc#def#ghi', 'comment' => null],
    ]);
});

test('parseEnvFormatToArray strips comments with space before hash', function () {
    $input = "PASSWORD=passw0rd #this is secure\nNODE_VERSION=22 #needed for now";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'PASSWORD' => ['value' => 'passw0rd', 'comment' => 'this is secure'],
        'NODE_VERSION' => ['value' => '22', 'comment' => 'needed for now'],
    ]);
});

test('parseEnvFormatToArray extracts comments from quoted values followed by comments', function () {
    $input = "KEY1=\"value\" #comment after quote\nKEY2='value' #another comment";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'KEY1' => ['value' => 'value', 'comment' => 'comment after quote'],
        'KEY2' => ['value' => 'value', 'comment' => 'another comment'],
    ]);
});

test('parseEnvFormatToArray handles empty comments', function () {
    $input = "KEY1=value #\nKEY2=value # ";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'KEY1' => ['value' => 'value', 'comment' => null],
        'KEY2' => ['value' => 'value', 'comment' => null],
    ]);
});

test('parseEnvFormatToArray extracts multi-word comments', function () {
    $input = 'DATABASE_URL=postgres://localhost #this is the database connection string for production';
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'DATABASE_URL' => ['value' => 'postgres://localhost', 'comment' => 'this is the database connection string for production'],
    ]);
});

test('parseEnvFormatToArray handles mixed quoted and unquoted with comments', function () {
    $input = "UNQUOTED=value1 #comment1\nDOUBLE=\"value2\" #comment2\nSINGLE='value3' #comment3";
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'UNQUOTED' => ['value' => 'value1', 'comment' => 'comment1'],
        'DOUBLE' => ['value' => 'value2', 'comment' => 'comment2'],
        'SINGLE' => ['value' => 'value3', 'comment' => 'comment3'],
    ]);
});

test('parseEnvFormatToArray handles the user reported case ASD=asd #asdfgg', function () {
    $input = 'ASD=asd #asdfgg';
    $result = parseEnvFormatToArray($input);

    expect($result)->toBe([
        'ASD' => ['value' => 'asd', 'comment' => 'asdfgg'],
    ]);

    // Specifically verify the comment is extracted
    expect($result['ASD']['value'])->toBe('asd');
    expect($result['ASD']['comment'])->toBe('asdfgg');
    expect($result['ASD']['comment'])->not->toBeNull();
});
