<?php

namespace App\Support;

use League\Flysystem\PhpseclibV3\ConnectionProvider;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\PhpseclibV3\UnableToEstablishAuthenticityOfHost;
use phpseclib3\Net\SFTP;

/**
 * An SFTP connection that checks a saved host key before it logs in (trust on first use). Without a saved key, it
 * records the key that the server presents, so the caller can save it.
 */
class PinnedSftpConnectionProvider implements ConnectionProvider
{
    private ?string $presentedHostKey = null;

    public function __construct(
        private ConnectionProvider $provider,
        private string $host,
        private ?string $pinnedHostKey,
    ) {}

    /**
     * @param  string  $host  The host name, for messages.
     * @param  string  $address  The IP to connect to.
     * @param  string|null  $pinnedHostKey  The saved host key ("ssh-ed25519 AAAA...").
     */
    public static function create(string $host, string $address, int $port, string $username, ?string $password, ?string $privateKey, ?string $pinnedHostKey, int $timeout): self
    {
        $provider = app(SftpConnectionProvider::class, [
            'host' => $address,
            'username' => $username,
            'password' => $password,
            'privateKey' => $privateKey,
            'port' => $port,
            'timeout' => $timeout,
            'hostFingerprint' => $pinnedHostKey !== null ? self::fingerprint($pinnedHostKey) : null,
            // Ask for the type of the saved key, else a server with several keys can present another one.
            'preferredAlgorithms' => $pinnedHostKey !== null ? ['hostkey' => self::hostKeyAlgorithms($pinnedHostKey)] : [],
        ]);

        return new self($provider, $host, $pinnedHostKey);
    }

    public function provideConnection(): SFTP
    {
        try {
            $connection = $this->provider->provideConnection();
        } catch (UnableToEstablishAuthenticityOfHost $exception) {
            throw $this->pinnedHostKey !== null ? SftpHostKeyChanged::atHost($this->host, $exception) : $exception;
        }

        if ($this->presentedHostKey === null) {
            $serverKey = $connection->getServerPublicHostKey();
            $this->presentedHostKey = is_string($serverKey) ? self::normalize($serverKey) : null;
        }

        return $connection;
    }

    public function disconnect(): void
    {
        $this->provider->disconnect();
    }

    /**
     * The host key that the server presented on the last connection, as "<key type> <base64>".
     */
    public function presentedHostKey(): ?string
    {
        return $this->presentedHostKey;
    }

    /**
     * The OpenSSH SHA256 fingerprint of a host key, as `ssh-keygen -l` prints it.
     */
    public static function fingerprint(string $hostKey): string
    {
        $blob = base64_decode(explode(' ', $hostKey)[1] ?? '', true);

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', (string) $blob, true)), '=');
    }

    /**
     * @return array<int, string>
     */
    private static function hostKeyAlgorithms(string $hostKey): array
    {
        $type = explode(' ', $hostKey)[0];

        return $type === 'ssh-rsa' ? ['rsa-sha2-512', 'rsa-sha2-256', 'ssh-rsa'] : [$type];
    }

    /**
     * phpseclib returns the signature algorithm with the key (for example "rsa-sha2-512" for an RSA key). rclone and
     * known_hosts need the key type, which is the first field of the key blob.
     */
    private static function normalize(string $serverKey): ?string
    {
        $base64 = explode(' ', $serverKey)[1] ?? '';
        $blob = base64_decode($base64, true);
        if ($blob === false || strlen($blob) < 4) {
            return null;
        }
        $type = substr($blob, 4, unpack('N', $blob)[1]);

        return preg_match('/^[a-z0-9@.-]+$/i', $type) === 1 ? $type.' '.$base64 : null;
    }
}
