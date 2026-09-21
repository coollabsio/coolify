<?php

namespace App\Services;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ProxyPortParser
{
    /**
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
                $ports[] = self::publishedPort($configuredPort);
            }
        }

        return array_values(array_unique($ports));
    }

    private static function publishedPort(mixed $configuredPort): int
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
                ? self::portNumber($configuredPort['published'])
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

        if (str_starts_with($portDefinition, '[')) {
            if (! preg_match('/^\[([^]]+)]:(\d+):(\d+)$/D', $portDefinition, $matches)) {
                self::invalid();
            }

            self::validateHostIp($matches[1]);
            self::portNumber($matches[3]);

            return self::portNumber($matches[2]);
        }

        $parts = explode(':', $portDefinition);
        if (count($parts) < 1 || count($parts) > 3) {
            self::invalid();
        }

        if (count($parts) === 3 && $parts[0] === '') {
            self::invalid();
        }

        if (count($parts) === 3) {
            self::validateHostIp($parts[0]);
        }

        $portParts = count($parts) === 3 ? array_slice($parts, 1) : $parts;
        foreach ($portParts as $part) {
            self::portNumber($part);
        }

        return self::portNumber($portParts[0]);
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
        if ($protocol !== null && (! is_string($protocol) || ! in_array($protocol, ['tcp', 'udp'], true))) {
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
        throw new \InvalidArgumentException('Proxy ports must be integers from 1 through 65535.');
    }
}
