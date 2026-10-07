<?php

/**
 * One-click templates that expose SERVICE_URL / SERVICE_FQDN without a _PORT
 * suffix (WordPress-style) must declare `# port:` so getRequiredPort() can
 * fall back for the badge and proxy.
 */
it('requires a template port for HTTP compose services that omit SERVICE_*_PORT', function () {
    $templates = get_service_templates();
    $missing = [];

    foreach (glob(base_path('templates/compose/*.{yaml,yml}'), GLOB_BRACE) as $file) {
        $name = pathinfo($file, PATHINFO_FILENAME);
        if (! $templates->has($name)) {
            continue;
        }

        $text = file_get_contents($file);
        $declaresHttpUrlWithoutPort = false;
        foreach (preg_split("/\r\n|\n|\r/", $text) as $line) {
            $line = trim($line);
            if (! preg_match('/^- SERVICE_(?:URL|FQDN)_([A-Z0-9_]+)$/', $line, $match)
                && ! preg_match('/^SERVICE_(?:URL|FQDN)_([A-Z0-9_]+):/', $line, $match)) {
                continue;
            }
            if (! preg_match('/_\d+$/', $match[1])) {
                $declaresHttpUrlWithoutPort = true;
                break;
            }
        }

        if (! $declaresHttpUrlWithoutPort) {
            continue;
        }

        $yamlPort = null;
        if (preg_match('/^#\s*port:\s*(\d+)/m', $text, $portMatch)) {
            $yamlPort = $portMatch[1];
        }
        $jsonPort = data_get($templates, "{$name}.port");

        if (! $yamlPort || ! filled($jsonPort)) {
            $missing[] = $name;
        }
    }

    expect($missing)->toBeEmpty('HTTP templates without SERVICE_*_PORT need # port: and JSON port: '.implode(', ', $missing));
});
