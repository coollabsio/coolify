<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Reads a plain or gzip tar from raw chunks (a local file or ranged S3 requests) in one pass, without a temporary
 * file. It hashes the raw bytes, and it unpacks every gzip member, as gzip does for `tar -xz` on the server.
 */
final class StaticSiteArchiveReader
{
    /** Raw bytes for each inflate call, so one call makes at most about 16 MiB (the zlib ratio is about 1:1032). */
    private const INFLATE_INPUT_BYTES = 16 * 1024;

    public readonly bool $isGzip;

    private string $buffer = '';

    private int $offset = 0;

    private string $pending = '';

    private ?\InflateContext $inflate = null;

    private \HashContext $hash;

    private int $rawBytes = 0;

    /**
     * @param  \Iterator<int, string>  $chunks
     */
    public function __construct(private readonly \Iterator $chunks, private readonly int $maxRawBytes)
    {
        $this->hash = hash_init('sha256');
        while (strlen($this->pending) < 2 && ($chunk = $this->nextRaw()) !== '') {
            $this->pending .= $chunk;
        }
        $this->isGzip = str_starts_with($this->pending, "\x1f\x8b");
    }

    /**
     * Returns $length bytes of the tar, or fewer at the end of the archive.
     *
     * @throws ValidationException
     */
    public function read(int $length): string
    {
        while (strlen($this->buffer) - $this->offset < $length && $this->fill()) {
        }
        $data = substr($this->buffer, $this->offset, $length);
        $this->offset += strlen($data);
        if ($this->offset > 1024 * 1024) {
            $this->buffer = substr($this->buffer, $this->offset);
            $this->offset = 0;
        }

        return $data;
    }

    /**
     * The site version: the first 40 hex characters of the SHA-256 of the whole raw archive.
     *
     * @throws ValidationException
     */
    public function sha(): string
    {
        while ($this->nextRaw() !== '') {
        }

        return substr(hash_final($this->hash), 0, 40);
    }

    private function fill(): bool
    {
        if ($this->pending === '') {
            $this->pending = $this->nextRaw();
            if ($this->pending === '') {
                return false;
            }
        }
        if (! $this->isGzip) {
            $this->buffer .= $this->pending;
            $this->pending = '';

            return true;
        }

        $this->inflate ??= inflate_init(ZLIB_ENCODING_GZIP);
        $input = substr($this->pending, 0, self::INFLATE_INPUT_BYTES);
        $readBefore = inflate_get_read_len($this->inflate);
        $output = @inflate_add($this->inflate, $input, ZLIB_SYNC_FLUSH);
        if ($output === false) {
            self::fail('The archive cannot be read.');
        }
        $used = inflate_get_read_len($this->inflate) - $readBefore;
        if ($used === 0 && $output === '') {
            self::fail('The archive cannot be read.');
        }
        $this->pending = substr($this->pending, $used);
        if (inflate_get_status($this->inflate) === ZLIB_STREAM_END) {
            // The next gzip member starts here. gzip unpacks it too, so it is part of the tar.
            $this->inflate = null;
        }
        $this->buffer .= $output;

        return true;
    }

    private function nextRaw(): string
    {
        while ($this->chunks->valid()) {
            $chunk = (string) $this->chunks->current();
            $this->chunks->next();
            if ($chunk === '') {
                continue;
            }
            $this->rawBytes += strlen($chunk);
            if ($this->rawBytes > $this->maxRawBytes) {
                self::fail('The archive is larger than 1 GiB.');
            }
            hash_update($this->hash, $chunk);

            return $chunk;
        }

        return '';
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['archive' => $message]);
    }
}
