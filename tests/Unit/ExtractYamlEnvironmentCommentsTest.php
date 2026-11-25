<?php

test('extractYamlEnvironmentComments returns empty array for YAML without environment section', function () {
    $yaml = <<<'YAML'
version: "3.8"
services:
  web:
    image: nginx:latest
    ports:
      - "80:80"
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    expect($result)->toBe([]);
});

test('extractYamlEnvironmentComments extracts inline comments from map format', function () {
    $yaml = <<<'YAML'
version: "3.8"
services:
  web:
    image: nginx:latest
    environment:
      FOO: bar  # This is a comment
      BAZ: qux
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    expect($result)->toBe([
        'FOO' => 'This is a comment',
    ]);
});

test('extractYamlEnvironmentComments extracts inline comments from array format', function () {
    $yaml = <<<'YAML'
version: "3.8"
services:
  web:
    image: nginx:latest
    environment:
      - FOO=bar  # This is a comment
      - BAZ=qux
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    expect($result)->toBe([
        'FOO' => 'This is a comment',
    ]);
});

test('extractYamlEnvironmentComments handles quoted values containing hash symbols', function () {
    $yaml = <<<'YAML'
version: "3.8"
services:
  web:
    image: nginx:latest
    environment:
      COLOR: "#FF0000"  # hex color code
      DB_URL: "postgres://user:pass#123@localhost"  # database URL
      PLAIN: value  # no quotes
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    expect($result)->toBe([
        'COLOR' => 'hex color code',
        'DB_URL' => 'database URL',
        'PLAIN' => 'no quotes',
    ]);
});

test('extractYamlEnvironmentComments handles single quoted values containing hash symbols', function () {
    $yaml = <<<'YAML'
version: "3.8"
services:
  web:
    image: nginx:latest
    environment:
      PASSWORD: 'secret#123'  # my password
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    expect($result)->toBe([
        'PASSWORD' => 'my password',
    ]);
});

test('extractYamlEnvironmentComments skips full-line comments', function () {
    $yaml = <<<'YAML'
version: "3.8"
services:
  web:
    image: nginx:latest
    environment:
      # This is a full line comment
      FOO: bar  # This is an inline comment
      # Another full line comment
      BAZ: qux
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    expect($result)->toBe([
        'FOO' => 'This is an inline comment',
    ]);
});

test('extractYamlEnvironmentComments handles multiple services', function () {
    $yaml = <<<'YAML'
version: "3.8"
services:
  web:
    image: nginx:latest
    environment:
      WEB_PORT: 8080  # web server port
  db:
    image: postgres:15
    environment:
      POSTGRES_USER: admin  # database admin user
      POSTGRES_PASSWORD: secret
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    expect($result)->toBe([
        'WEB_PORT' => 'web server port',
        'POSTGRES_USER' => 'database admin user',
    ]);
});

test('extractYamlEnvironmentComments handles variables without values', function () {
    $yaml = <<<'YAML'
version: "3.8"
services:
  web:
    image: nginx:latest
    environment:
      - DEBUG  # enable debug mode
      - VERBOSE
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    expect($result)->toBe([
        'DEBUG' => 'enable debug mode',
    ]);
});

test('extractYamlEnvironmentComments handles array format with colons', function () {
    $yaml = <<<'YAML'
version: "3.8"
services:
  web:
    image: nginx:latest
    environment:
      - DATABASE_URL: postgres://localhost  # connection string
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    expect($result)->toBe([
        'DATABASE_URL' => 'connection string',
    ]);
});

test('extractYamlEnvironmentComments does not treat hash inside unquoted values as comment start', function () {
    $yaml = <<<'YAML'
version: "3.8"
services:
  web:
    image: nginx:latest
    environment:
      API_KEY: abc#def
      OTHER: xyz  # this is a comment
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    // abc#def has no space before #, so it's not treated as a comment
    expect($result)->toBe([
        'OTHER' => 'this is a comment',
    ]);
});

test('extractYamlEnvironmentComments handles empty environment section', function () {
    $yaml = <<<'YAML'
version: "3.8"
services:
  web:
    image: nginx:latest
    environment:
    ports:
      - "80:80"
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    expect($result)->toBe([]);
});

test('extractYamlEnvironmentComments handles environment inline format (not supported)', function () {
    // Inline format like environment: { FOO: bar } is not supported for comment extraction
    $yaml = <<<'YAML'
version: "3.8"
services:
  web:
    image: nginx:latest
    environment: { FOO: bar }
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    // No comments extracted from inline format
    expect($result)->toBe([]);
});

test('extractYamlEnvironmentComments handles complex real-world docker-compose', function () {
    $yaml = <<<'YAML'
version: "3.8"

services:
  app:
    image: myapp:latest
    environment:
      NODE_ENV: production  # Set to development for local
      DATABASE_URL: "postgres://user:pass@db:5432/mydb"  # Main database
      REDIS_URL: "redis://cache:6379"
      API_SECRET: "${API_SECRET}"  # From .env file
      LOG_LEVEL: debug  # Options: debug, info, warn, error
    ports:
      - "3000:3000"

  db:
    image: postgres:15
    environment:
      POSTGRES_USER: user  # Database admin username
      POSTGRES_PASSWORD: "${DB_PASSWORD}"
      POSTGRES_DB: mydb

  cache:
    image: redis:7
    environment:
      - REDIS_MAXMEMORY=256mb  # Memory limit for cache
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    expect($result)->toBe([
        'NODE_ENV' => 'Set to development for local',
        'DATABASE_URL' => 'Main database',
        'API_SECRET' => 'From .env file',
        'LOG_LEVEL' => 'Options: debug, info, warn, error',
        'POSTGRES_USER' => 'Database admin username',
        'REDIS_MAXMEMORY' => 'Memory limit for cache',
    ]);
});

test('extractYamlEnvironmentComments handles comment with multiple hash symbols', function () {
    $yaml = <<<'YAML'
version: "3.8"
services:
  web:
    environment:
      FOO: bar  # comment # with # hashes
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    expect($result)->toBe([
        'FOO' => 'comment # with # hashes',
    ]);
});

test('extractYamlEnvironmentComments handles variables with empty comments', function () {
    $yaml = <<<'YAML'
version: "3.8"
services:
  web:
    environment:
      FOO: bar  #
      BAZ: qux  #
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    // Empty comments should not be included
    expect($result)->toBe([]);
});

test('extractYamlEnvironmentComments properly exits environment block on new section', function () {
    $yaml = <<<'YAML'
version: "3.8"
services:
  web:
    image: nginx:latest
    environment:
      FOO: bar  # env comment
    ports:
      - "80:80"  # port comment should not be captured
    volumes:
      - ./data:/data  # volume comment should not be captured
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    // Only environment variables should have comments extracted
    expect($result)->toBe([
        'FOO' => 'env comment',
    ]);
});

test('extractYamlEnvironmentComments handles SERVICE_ variables', function () {
    $yaml = <<<'YAML'
version: "3.8"
services:
  web:
    environment:
      SERVICE_FQDN_WEB: /api  # Path for the web service
      SERVICE_URL_WEB:  # URL will be generated
      NORMAL_VAR: value  # Regular variable
YAML;

    $result = extractYamlEnvironmentComments($yaml);

    expect($result)->toBe([
        'SERVICE_FQDN_WEB' => 'Path for the web service',
        'SERVICE_URL_WEB' => 'URL will be generated',
        'NORMAL_VAR' => 'Regular variable',
    ]);
});
