<?php

namespace App\Support;

use App\Models\Node;

/**
 * CPU architectures of Nodes and images (decision 0020), in the names of the OCI image index:
 * `uname -m` x86_64 and the image `amd64` are both `amd64`. An unknown architecture is null and
 * fits everything, so a Node or an image that Coolify cannot inspect is not blocked.
 */
final class CpuArchitecture
{
    private const ALIASES = [
        'x86_64' => 'amd64',
        'x86-64' => 'amd64',
        'amd64' => 'amd64',
        'aarch64' => 'arm64',
        'arm64' => 'arm64',
        'arm64/v8' => 'arm64',
        'armv7l' => 'arm/v7',
        'armv7' => 'arm/v7',
        'armhf' => 'arm/v7',
        'arm' => 'arm/v7',
        'arm/v7' => 'arm/v7',
        'armv6l' => 'arm/v6',
        'arm/v6' => 'arm/v6',
        'i386' => '386',
        'i686' => '386',
        '386' => '386',
        'ppc64le' => 'ppc64le',
        's390x' => 's390x',
        'riscv64' => 'riscv64',
    ];

    /** The OCI name of an architecture (with the variant of an image platform), or null when it is unknown. */
    public static function normalize(?string $architecture, ?string $variant = null): ?string
    {
        $architecture = strtolower(trim((string) $architecture));
        if ($architecture === '' || $architecture === 'unknown') {
            return null;
        }
        $variant = strtolower(trim((string) $variant));
        if ($variant !== '' && $architecture === 'arm') {
            $architecture .= '/'.$variant;
        }

        return self::ALIASES[$architecture] ?? $architecture;
    }

    /**
     * Why the Node cannot run an image with these architectures, or null when it can.
     *
     * @param  list<string>|null  $architectures  Null when the architectures of the image are unknown. An empty
     *                                            list (images without a common architecture) fits no Node.
     */
    public static function mismatch(Node $node, ?array $architectures): ?string
    {
        $nodeArchitecture = $node->cpuArchitecture();
        if ($architectures === null || $nodeArchitecture === null || in_array($nodeArchitecture, $architectures, true)) {
            return null;
        }
        if ($architectures === []) {
            return 'The images have no CPU architecture in common.';
        }

        return "Server {$node->name} has the {$nodeArchitecture} CPU architecture, but the image is only for ".implode(', ', $architectures).'.';
    }

    /**
     * A server option of a listbox: the architecture of the Node as the description, or the reason why
     * it does not fit, then disabled.
     *
     * @param  list<string>|null  $architectures
     * @return array{value: string, label: string, description: ?string, disabled: bool}
     */
    public static function nodeOption(Node $node, ?array $architectures): array
    {
        $mismatch = self::mismatch($node, $architectures);

        return ['value' => $node->uuid, 'label' => $node->name, 'description' => $mismatch ?? $node->cpuArchitecture(), 'disabled' => $mismatch !== null];
    }

    /**
     * The architectures that every image of a set supports: the intersection, without unknown images.
     *
     * @param  iterable<list<string>|null>  $sets
     * @return list<string>|null
     */
    public static function common(iterable $sets): ?array
    {
        $common = null;
        foreach ($sets as $set) {
            if ($set !== null) {
                $common = $common === null ? $set : array_values(array_intersect($common, $set));
            }
        }

        return $common;
    }
}
