<?php

namespace App\Services;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

class ProxyPortParser
{
    /**
     * Matches a Docker Compose variable: $VAR, ${VAR}, or ${VAR} with a :-, -, :?, ?, :+, or + modifier.
     */
    private const VARIABLE_PATTERN = '/\$(?:\{[A-Za-z_][A-Za-z0-9_]*(?::?[-?+][^${}]*)?\}|[A-Za-z_][A-Za-z0-9_]*)/';

    /**
     * Matches ${VAR:-default} and ${VAR-default}; group 1 is the default.
     */
    private const DEFAULT_PATTERN = '/\$\{[A-Za-z_][A-Za-z0-9_]*:?-([^${}]*)\}/';

    /**
     * Characters a port entry may contain outside of its variables.
     */
    private const SAFE_REMAINDER_PATTERN = '/^[0-9A-Za-z.:\[\]\/-]*$/D';

    /**
     * Validates the proxy ports like Docker Compose does and returns the fixed host ports.
     * Ports that Docker publishes to a random host port or to a port range are validated
     * but not returned: the caller checks each returned port over its own SSH connection.
     *
     * Docker Compose interpolates variables when it starts the proxy, so a variable never makes
     * a port invalid: a variable with a default is checked as its default, and a port that still
     * depends on a variable (or whose default is not a valid port) is accepted but not returned.
     *
     * Docker Compose merge tags such as `!reset` and `!override` are accepted; their values are validated.
     *
     * @return list<int>
     */
    public static function fromConfiguration(string $configuration): array
    {
        try {
            $parsed = self::withoutTags(Yaml::parse($configuration, Yaml::PARSE_CUSTOM_TAGS));
        } catch (ParseException $exception) {
            throw new \InvalidArgumentException('The proxy configuration must contain valid YAML.', previous: $exception);
        }

        if (! is_array($parsed)) {
            return [];
        }

        $ports = [];

        foreach (['traefik', 'caddy'] as $proxyService) {
            $path = "services.{$proxyService}.ports";

            if (! data_has($parsed, $path)) {
                continue;
            }

            $configuredPorts = data_get($parsed, $path);
            if (! is_array($configuredPorts) || ! array_is_list($configuredPorts)) {
                self::invalid();
            }

            foreach ($configuredPorts as $configuredPort) {
                $publishedPort = self::publishedPort($configuredPort);
                if ($publishedPort !== null) {
                    $ports[] = $publishedPort;
                }
            }
        }

        return array_values(array_unique($ports));
    }

    private static function withoutTags(mixed $value): mixed
    {
        if ($value instanceof TaggedValue) {
            return self::withoutTags($value->getValue());
        }

        if (is_array($value)) {
            return array_map(self::withoutTags(...), $value);
        }

        return $value;
    }

    private static function publishedPort(mixed $configuredPort): ?int
    {
        if (is_string($configuredPort) && self::containsVariable($configuredPort)) {
            return self::publishedPortWithVariables($configuredPort);
        }

        if (is_array($configuredPort)) {
            if (array_is_list($configuredPort) || ! array_key_exists('target', $configuredPort)) {
                self::invalid();
            }

            $configuredPort = self::resolveLongSyntaxVariables($configuredPort, $hasUnresolvedVariables);

            self::validateProtocol($configuredPort['protocol'] ?? null);
            if (array_key_exists('host_ip', $configuredPort)) {
                self::validateHostIp($configuredPort['host_ip']);
            }
            $target = self::portNumber($configuredPort['target']);
            $publishedPort = array_key_exists('published', $configuredPort)
                ? self::portOrRange($configuredPort['published'])
                : $target;

            return $hasUnresolvedVariables ? null : $publishedPort;
        }

        if (! is_int($configuredPort) && ! is_string($configuredPort)) {
            self::invalid();
        }

        if (is_int($configuredPort)) {
            return self::portNumber($configuredPort);
        }

        $portDefinition = $configuredPort;
        $protocolSeparator = strrpos($portDefinition, '/');
        if ($protocolSeparator !== false) {
            self::validateProtocol(substr($portDefinition, $protocolSeparator + 1));
            $portDefinition = substr($portDefinition, 0, $protocolSeparator);
        }

        // [HOST_IP:][HOST_PORT:]CONTAINER_PORT, where each port may be a range and an
        // empty HOST_PORT after a host IP means a random host port.
        if (str_starts_with($portDefinition, '[')) {
            if (! preg_match('/^\[([^]]+)]:([^:]*):([^:]+)$/D', $portDefinition, $matches)) {
                self::invalid();
            }
            self::validateHostIp($matches[1]);

            return self::hostPort($matches[2], $matches[3]);
        }

        $parts = explode(':', $portDefinition);
        if (count($parts) > 3 || (count($parts) > 1 && $parts[0] === '')) {
            self::invalid();
        }
        if (count($parts) === 3) {
            self::validateHostIp(array_shift($parts));
        }

        return count($parts) === 1
            ? self::portOrRange($parts[0])
            : self::hostPort($parts[0], $parts[1]);
    }

    private static function containsVariable(string $value): bool
    {
        return preg_match(self::VARIABLE_PATTERN, $value) === 1;
    }

    /**
     * Rejects an entry with anything but variables and port syntax, then replaces each
     * variable that has a default with the default. Returns null if a variable remains.
     */
    private static function resolveDefaults(string $value): ?string
    {
        if (preg_match(self::SAFE_REMAINDER_PATTERN, preg_replace(self::VARIABLE_PATTERN, '', $value)) !== 1) {
            self::invalid();
        }

        $resolved = preg_replace_callback(self::DEFAULT_PATTERN, fn (array $matches): string => $matches[1], $value);

        return self::containsVariable($resolved) ? null : $resolved;
    }

    private static function publishedPortWithVariables(string $configuredPort): ?int
    {
        $resolved = self::resolveDefaults($configuredPort);
        if ($resolved === null) {
            return null;
        }

        try {
            return self::publishedPort($resolved);
        } catch (\InvalidArgumentException) {
            // The variable may be set to a valid value when Docker Compose starts the proxy.
            return null;
        }
    }

    /**
     * Replaces long-syntax fields that use variables with their defaults. A field without a usable
     * default is replaced with a valid placeholder (or removed) so the other fields are still validated.
     *
     * @param  array<string, mixed>  $configuredPort
     * @return array<string, mixed>
     */
    private static function resolveLongSyntaxVariables(array $configuredPort, ?bool &$hasUnresolvedVariables): array
    {
        $hasUnresolvedVariables = false;
        $validators = [
            'target' => fn (string $value) => self::portOrRange($value),
            'published' => fn (string $value) => self::portOrRange($value),
            'host_ip' => fn (string $value) => self::validateHostIp($value),
            'protocol' => fn (string $value) => self::validateProtocol($value),
        ];

        foreach ($validators as $field => $validate) {
            $value = $configuredPort[$field] ?? null;
            if (! is_string($value) || ! self::containsVariable($value)) {
                continue;
            }

            $resolved = self::resolveDefaults($value);
            if ($resolved !== null) {
                try {
                    $validate($resolved);
                    $configuredPort[$field] = $resolved;

                    continue;
                } catch (\InvalidArgumentException) {
                    // The variable may be set to a valid value when Docker Compose starts the proxy.
                }
            }

            $hasUnresolvedVariables = true;
            if (in_array($field, ['target', 'published'], true)) {
                $configuredPort[$field] = 1;
            } else {
                unset($configuredPort[$field]);
            }
        }

        return $configuredPort;
    }

    /**
     * Returns the fixed host port, or null for a random host port or a port range.
     */
    private static function hostPort(string $hostPort, string $containerPort): ?int
    {
        self::portOrRange($containerPort);

        return $hostPort === '' ? null : self::portOrRange($hostPort);
    }

    /**
     * Returns the port, or null for a valid port range such as 10000-10100.
     */
    private static function portOrRange(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^(\d+)-(\d+)$/D', $value, $range)) {
            if (self::portNumber($range[1]) > self::portNumber($range[2])) {
                self::invalid();
            }

            return null;
        }

        return self::portNumber($value);
    }

    private static function portNumber(mixed $port): int
    {
        if (is_int($port)) {
            if ($port < 1 || $port > 65535) {
                self::invalid();
            }

            return $port;
        }

        if (! is_string($port) || preg_match('/^\d+$/D', $port) !== 1) {
            self::invalid();
        }

        $normalized = (int) $port;
        if ($normalized < 1 || $normalized > 65535) {
            self::invalid();
        }

        return $normalized;
    }

    private static function validateProtocol(mixed $protocol): void
    {
        // Docker Compose accepts the protocol in any letter case.
        if ($protocol !== null && (! is_string($protocol) || ! in_array(strtolower($protocol), ['tcp', 'udp', 'sctp'], true))) {
            self::invalid();
        }
    }

    private static function validateHostIp(mixed $hostIp): void
    {
        if (! is_string($hostIp) || filter_var($hostIp, FILTER_VALIDATE_IP) === false) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new \InvalidArgumentException('Proxy ports must use Docker Compose port syntax with ports from 1 through 65535.');
    }
}
