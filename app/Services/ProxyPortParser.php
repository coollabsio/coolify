<?php

namespace App\Services;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ProxyPortParser
{
    /**
     * Validates the proxy ports like Docker Compose does and returns the fixed host ports.
     * Ports that Docker publishes to a random host port or to a port range are validated
     * but not returned: the caller checks each returned port over its own SSH connection.
     *
     * @return list<int>
     */
    public static function fromConfiguration(string $configuration): array
    {
        try {
            $parsed = Yaml::parse($configuration);
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

    private static function publishedPort(mixed $configuredPort): ?int
    {
        if (is_array($configuredPort)) {
            if (array_is_list($configuredPort) || ! array_key_exists('target', $configuredPort)) {
                self::invalid();
            }

            self::validateProtocol($configuredPort['protocol'] ?? null);
            if (array_key_exists('host_ip', $configuredPort)) {
                self::validateHostIp($configuredPort['host_ip']);
            }
            $target = self::portNumber($configuredPort['target']);

            return array_key_exists('published', $configuredPort)
                ? self::portOrRange($configuredPort['published'])
                : $target;
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
