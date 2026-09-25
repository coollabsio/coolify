<?php

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/missing_strings.php <html-file> <dict.json>\n");
    exit(1);
}

$html = (string) file_get_contents($argv[1]);
$dict = json_decode((string) file_get_contents($argv[2]), true);
if (! is_array($dict)) {
    fwrite(STDERR, "Invalid dictionary\n");
    exit(1);
}

$html = preg_replace(
    '/<(script|style|pre|code|textarea)\b[^>]*>.*?<\/\1>|<!--.*?-->/is',
    '',
    $html
);
if (! is_string($html)) {
    fwrite(STDERR, "Failed to strip protected regions\n");
    exit(1);
}

preg_match_all('/>([^<>]+)</', $html, $matches);
$missing = [];
foreach ($matches[1] as $raw) {
    $text = trim($raw);
    if ($text === '' || strlen($text) <= 1) {
        continue;
    }
    if (isset($dict[$text])) {
        continue;
    }
    if (preg_match('/[{}$<>]|http|\/\//', $text)) {
        continue;
    }
    if (! preg_match('/\p{L}/u', $text)) {
        continue;
    }
    if (preg_match('/^\d{4}-[A-Za-z]{3}-\d{2}$/', $text)) {
        continue;
    }
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $text)) {
        continue;
    }
    if (preg_match('/^\d+m \d+s$/', $text)) {
        continue;
    }
    if (preg_match('/^#?[0-9a-f]{3,8}$/i', $text)) {
        continue;
    }
    if (preg_match('/^\d+[–-]\d+ of \d+$/u', $text)) {
        continue;
    }
    if (preg_match('/^\d+\s*(ms|s|m|h|d|KB|MB|GB)$/', $text)) {
        continue;
    }
    $missing[$text] = true;
}

$keys = array_keys($missing);
sort($keys, SORT_STRING);
foreach ($keys as $key) {
    echo $key . "\n";
}
echo 'MISSING ' . count($keys) . "\n";
