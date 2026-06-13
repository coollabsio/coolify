<?php

$dir = __DIR__;

function process_directory($path) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $content = file_get_contents($file->getPathname());
            $original = $content;

            // Replace in executeInDocker
            $content = preg_replace(
                '/executeInDocker\(([^,]+),\s*"echo \'\{\$([a-zA-Z0-9_>-]+)\}\' \| base64 -d \| tee (.*?) > \/dev\/null"\)/',
                'base64_to_file_in_docker(\1, $\2, "\3")',
                $content
            );

            // Replace standalone commands
            $content = preg_replace(
                '/"echo \'\{\$([a-zA-Z0-9_>-]+)\}\' \| base64 -d \| tee (.*?) > \/dev\/null"/',
                'base64_to_file($\1, "\2")',
                $content
            );

            if ($content !== $original) {
                file_put_contents($file->getPathname(), $content);
                echo "Updated: " . $file->getPathname() . "\n";
            }
        }
    }
}

process_directory($dir . '/app/Jobs');
process_directory($dir . '/app/Actions');

$dockerHelpersPath = $dir . '/bootstrap/helpers/docker.php';
$helpersContent = file_get_contents($dockerHelpersPath);
if (strpos($helpersContent, 'base64_to_file') === false) {
    $add = <<<PHP

function base64_to_file(string \$base64, string \$path): string
{
    \$tempFile = '/tmp/coolify_' . uniqid() . '.b64';
    return implode("\\n", [
        "cat << 'EOF_COOLIFY_B64' > {\$tempFile}",
        \$base64,
        "EOF_COOLIFY_B64",
        "cat {\$tempFile} | base64 -d > {\$path}",
        "rm -f {\$tempFile}"
    ]);
}

function base64_to_file_in_docker(string \$containerId, string \$base64, string \$path): string
{
    \$tempFile = '/tmp/coolify_' . uniqid() . '.b64';
    return implode("\\n", [
        "cat << 'EOF_COOLIFY_B64' > {\$tempFile}",
        \$base64,
        "EOF_COOLIFY_B64",
        "cat {\$tempFile} | base64 -d > {\$tempFile}.decoded",
        "docker cp {\$tempFile}.decoded {\$containerId}:{\$path}",
        "rm -f {\$tempFile} {\$tempFile}.decoded"
    ]);
}
PHP;
    file_put_contents($dockerHelpersPath, $helpersContent . $add);
    echo "Added helper functions to docker.php\n";
}

echo "Berhasil! Semua bug telah diperbaiki.\n";