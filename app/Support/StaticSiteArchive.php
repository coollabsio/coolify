<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Pion\Laravel\ChunkUpload\Handler\HandlerFactory;
use Pion\Laravel\ChunkUpload\Receiver\FileReceiver;

/**
 * A static site archive (a plain tar or a gzip tar) that a user uploads.
 *
 * inspect() reads the archive as a stream and accepts only regular files and directories with
 * relative paths, so tar can extract it on the server without links, devices, or paths outside
 * the work directory. The first 40 hex characters of the SHA-256 of the archive are the site
 * version, which the deployment uses as its commit and image tag.
 */
final class StaticSiteArchive
{
    public const MAX_COMPRESSED_BYTES = 1024 ** 3;

    public const MAX_EXTRACTED_BYTES = 5 * 1024 ** 3;

    public const MAX_ENTRIES = 100_000;

    private const MAX_METADATA_BYTES = 1024 * 1024;

    private const MAX_PATH_BYTES = 4096;

    private const BLOCK = 512;

    private function __construct(
        public readonly string $sha,
        public readonly bool $isGzip,
        public readonly int $stripComponents,
        public readonly int $fileCount,
        public readonly int $totalBytes,
    ) {}

    /**
     * @throws ValidationException when the file is not an acceptable site archive
     */
    public static function inspect(string $path): self
    {
        $size = @filesize($path);
        if (! $size) {
            self::fail('The archive is empty.');
        }
        if ($size > self::MAX_COMPRESSED_BYTES) {
            self::fail('The archive is larger than 1 GiB.');
        }

        $handle = fopen($path, 'rb');
        $isGzip = fread($handle, 2) === "\x1f\x8b";
        fclose($handle);

        $stream = gzopen($path, 'rb');
        if ($stream === false) {
            self::fail('The archive cannot be read.');
        }

        try {
            $entries = 0;
            $fileCount = 0;
            $totalBytes = 0;
            $longName = null;
            $pax = [];
            $topLevelNames = [];
            $topLevelHasFile = false;
            $leadingDotCounts = [];

            while (true) {
                $header = gzread($stream, self::BLOCK);
                if ($header === '' || $header === false) {
                    break;
                }
                if (strlen($header) !== self::BLOCK) {
                    self::fail('The archive is truncated.');
                }
                if ($header === str_repeat("\0", self::BLOCK)) {
                    break;
                }
                if (++$entries > self::MAX_ENTRIES) {
                    self::fail('The archive has more than '.number_format(self::MAX_ENTRIES).' entries.');
                }
                if (! self::hasValidChecksum($header)) {
                    self::fail('The file is not a tar archive, or it is damaged.');
                }

                $type = $header[156];
                $headerSize = self::number(substr($header, 124, 12));

                if ($type === 'L' || $type === 'x') {
                    if ($headerSize > self::MAX_METADATA_BYTES) {
                        self::fail('The archive has an entry with too much metadata.');
                    }
                    $data = self::readData($stream, $headerSize, keep: true);
                    if ($type === 'L') {
                        $longName = rtrim($data, "\0");
                    } else {
                        $pax = self::paxRecords($data);
                    }

                    continue;
                }
                if ($type === 'g') {
                    if ($headerSize > self::MAX_METADATA_BYTES) {
                        self::fail('The archive has an entry with too much metadata.');
                    }
                    $global = self::paxRecords(self::readData($stream, $headerSize, keep: true));
                    if (array_intersect_key($global, array_flip(['path', 'linkpath', 'size'])) !== []) {
                        self::fail('The archive has a global header that changes entry paths or sizes.');
                    }

                    continue;
                }

                $dataSize = $pax['size'] ?? $headerSize;
                $name = $longName ?? $pax['path'] ?? self::headerName($header);
                $longName = null;
                $pax = [];

                $isFile = $type === '0' || $type === "\0" || $type === '7';
                if (! $isFile && $type !== '5') {
                    self::fail('The archive entry '.self::display($name).' is a link, a device, or another special file. Only regular files and directories are allowed.');
                }
                if (! $isFile && $dataSize > 0) {
                    self::fail('The archive entry '.self::display($name).' is a directory with data.');
                }
                $segments = self::pathSegments($name);

                if ($isFile) {
                    $fileCount++;
                    $totalBytes += $dataSize;
                    if ($totalBytes > self::MAX_EXTRACTED_BYTES) {
                        self::fail('The extracted site is larger than 5 GiB.');
                    }
                }
                self::readData($stream, $dataSize);

                $leadingDots = 0;
                while (($segments[$leadingDots] ?? null) === '.') {
                    $leadingDots++;
                }
                $leadingDotCounts[$leadingDots] = true;
                $parts = array_values(array_filter($segments, fn (string $segment): bool => $segment !== '' && $segment !== '.'));
                if ($parts === [] || $parts[0] === '__MACOSX' || ($parts === ['.DS_Store'])) {
                    continue;
                }
                $topLevelNames[$parts[0]] = true;
                if ($isFile && count($parts) === 1) {
                    $topLevelHasFile = true;
                }
            }
        } finally {
            gzclose($stream);
        }

        if ($fileCount === 0) {
            self::fail('The archive contains no files.');
        }

        // GNU tar counts a leading "./" as a path component, so --strip-components covers it too.
        $stripComponents = 0;
        if (count($topLevelNames) === 1 && ! $topLevelHasFile && count($leadingDotCounts) === 1) {
            $stripComponents = array_key_first($leadingDotCounts) + 1;
        }

        return new self(substr(hash_file('sha256', $path), 0, 40), $isGzip, $stripComponents, $fileCount, $totalBytes);
    }

    /**
     * Receives one DropZone chunk of an archive upload for the team. The last chunk validates the
     * archive and stages it. The upload ID is the DropZone file UUID (dzuuid) when it is not sent.
     */
    public static function receiveUpload(Request $request, int $teamId): JsonResponse
    {
        $uploadId = (string) $request->input('upload_id', $request->input('dzuuid', ''));
        $request->merge(['upload_id' => $uploadId, 'dzuuid' => $request->input('dzuuid', $uploadId)]);
        $validator = Validator::make($request->all(), ['upload_id' => ['required', 'uuid'], 'file' => ['required', 'file']]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }
        if ((int) $request->input('dzTotalFilesize', 0) > self::MAX_COMPRESSED_BYTES) {
            return response()->json(['message' => 'The archive is larger than 1 GiB.', 'errors' => ['file' => ['The archive is larger than 1 GiB.']]], 422);
        }

        $save = (new FileReceiver('file', $request, HandlerFactory::classFromRequest($request)))->receive();
        if (! $save->isFinished()) {
            return response()->json(['upload_id' => $uploadId, 'done' => $save->handler()->getPercentageDone(), 'status' => true]);
        }

        $file = $save->getFile();
        $size = $file->getSize();
        try {
            $archive = self::stage($file, $teamId, $uploadId);
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => $exception->errors()], 422);
        }

        return response()->json(['upload_id' => strtolower($uploadId), 'size' => $size, 'sha' => $archive->sha], 201);
    }

    /**
     * Validates a finished upload and moves it to the staging directory of the team.
     *
     * @throws ValidationException
     */
    public static function stage(UploadedFile $file, int $teamId, string $uploadId): self
    {
        try {
            $archive = self::inspect($file->getPathname());
        } catch (ValidationException $exception) {
            @unlink($file->getPathname());

            throw $exception;
        }
        $directory = self::stagingDirectory($teamId, $uploadId);
        Storage::makeDirectory($directory);
        $file->move(Storage::path($directory), 'archive');

        return $archive;
    }

    /**
     * Staged uploads wait here until an application takes them. CleanupDatabaseImportUploads deletes old ones.
     */
    public static function stagingDirectory(int $teamId, string $uploadId): string
    {
        if (! Str::isUuid($uploadId)) {
            self::fail('The upload ID must be a UUID.');
        }

        return "upload/static-archives/{$teamId}/".strtolower($uploadId);
    }

    public static function hasStagedUpload(int $teamId, string $uploadId): bool
    {
        return Storage::exists(self::stagingDirectory($teamId, $uploadId).'/archive');
    }

    /**
     * Archives wait here, one per deployment, until the deployment copies them to the server. The image on the
     * server is the only stored copy of a site version. CleanupDatabaseImportUploads deletes orphans.
     */
    public static function storageDirectory(Application $application): string
    {
        return "applications/{$application->uuid}/static-archives";
    }

    public static function deploymentArchive(Application $application, string $deploymentUuid): string
    {
        return self::storageDirectory($application)."/{$deploymentUuid}";
    }

    /**
     * Moves a staged upload to the archive of a new deployment and queues the deployment, which builds the image
     * from it. A queued deployment makes the archive the current site version.
     *
     * @return array{sha: string, status: string, message: ?string, deployment_uuid: ?string}
     *
     * @throws ValidationException when the upload is not staged for the team
     */
    public static function publish(Application $application, int $teamId, string $uploadId, bool $isApi = false): array
    {
        $staged = self::stagingDirectory($teamId, $uploadId).'/archive';
        if (! Storage::exists($staged)) {
            self::fail('The upload was not found or has expired. Upload the archive again.', 'upload_id');
        }
        $sha = substr(hash_file('sha256', Storage::path($staged)), 0, 40);
        $deploymentUuid = new_public_id();
        $target = self::deploymentArchive($application, $deploymentUuid);
        Storage::makeDirectory(self::storageDirectory($application));
        if (! rename(Storage::path($staged), Storage::path($target))) {
            self::fail('The upload cannot be stored. Upload the archive again.', 'upload_id');
        }
        Storage::deleteDirectory(dirname($staged));

        // force_rebuild records that this deployment builds, and keeps a queued redeploy of the same version
        // (which has no archive) from absorbing it.
        $result = queue_application_deployment(
            application: $application,
            deployment_uuid: $deploymentUuid,
            commit: $sha,
            force_rebuild: true,
            is_api: $isApi,
        );
        if (($result['deployment_uuid'] ?? null) === $deploymentUuid) {
            $application->update(['git_commit_sha' => $sha]);
        } else {
            Storage::delete($target);
        }

        return [
            'sha' => $sha,
            'status' => $result['status'],
            'message' => $result['message'] ?? null,
            'deployment_uuid' => $result['deployment_uuid'] ?? null,
        ];
    }

    /**
     * Creates a static site application that is deployed from uploaded archives. Without a domain,
     * it gets a generated one when $autogenerateDomain is true.
     */
    public static function createApplication(Environment $environment, StandaloneDocker|SwarmDocker $destination, ?string $name = null, ?string $description = null, ?string $fqdn = null, bool $autogenerateDomain = true, bool $isSpa = false): Application
    {
        $application = Application::create([
            'name' => filled($name) ? $name : 'static-site-'.new_public_id(),
            'description' => $description,
            'fqdn' => filled($fqdn) ? $fqdn : null,
            'repository_project_id' => 0,
            'git_repository' => 'coollabsio/coolify',
            'git_branch' => 'main',
            'build_pack' => 'static',
            'static_image' => 'nginx:alpine',
            'ports_exposes' => '80',
            'custom_nginx_configuration' => defaultNginxConfiguration($isSpa ? 'spa' : 'static'),
            'is_archive_source' => true,
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'health_check_enabled' => false,
            'source_id' => 0,
            'source_type' => GithubApp::class,
        ]);
        if (blank($fqdn) && $autogenerateDomain) {
            $application->update(['fqdn' => generateUrl(server: $destination->server, random: $application->uuid)]);
        }
        if ($isSpa) {
            $application->settings->update(['is_spa' => true]);
        }

        return $application->refresh();
    }

    /**
     * The tar command that extracts the archive into the work directory of the deployment.
     */
    public function extractCommand(string $archivePath, string $workdir): string
    {
        $command = 'tar -x'.($this->isGzip ? 'z' : '').'f '.escapeshellarg($archivePath).' -C '.escapeshellarg($workdir)
            .' --no-same-owner --no-same-permissions --exclude=__MACOSX --exclude=.DS_Store';
        if ($this->stripComponents > 0) {
            $command .= " --strip-components={$this->stripComponents}";
        }

        return $command;
    }

    private static function hasValidChecksum(string $header): bool
    {
        $stored = trim(substr($header, 148, 8), "\0 ");
        if ($stored === '' || ! preg_match('/^[0-7]+$/', $stored)) {
            return false;
        }

        $header = substr_replace($header, '        ', 148, 8);
        $stored = octdec($stored);

        // Some old tar writers sum signed bytes, GNU tar accepts both.
        return array_sum(unpack('C*', $header)) === $stored || array_sum(unpack('c*', $header)) === $stored;
    }

    /**
     * A numeric header field: octal digits, or base-256 when the first byte has the high bit set.
     */
    private static function number(string $field): int
    {
        $first = ord($field[0] ?? "\0");
        if ($first & 0x80) {
            if ($first === 0xFF) {
                self::fail('The archive has an entry with a negative size.');
            }
            $value = $first & 0x7F;
            for ($i = 1; $i < strlen($field); $i++) {
                if ($value > intdiv(PHP_INT_MAX, 256)) {
                    self::fail('The archive has an entry that is too large.');
                }
                $value = $value * 256 + ord($field[$i]);
            }

            return $value;
        }
        $digits = trim($field, "\0 ");
        if ($digits === '') {
            return 0;
        }
        if (! preg_match('/^[0-7]{1,21}$/', $digits)) {
            self::fail('The file is not a tar archive, or it is damaged.');
        }

        return (int) octdec($digits);
    }

    private static function headerName(string $header): string
    {
        $name = self::cString(substr($header, 0, 100));
        // POSIX ustar ("ustar\0") stores the start of long paths in the prefix field. GNU tar ("ustar  ") uses that space for other data.
        if (substr($header, 257, 6) === "ustar\0") {
            $prefix = self::cString(substr($header, 345, 155));
            if ($prefix !== '') {
                $name = "{$prefix}/{$name}";
            }
        }

        return $name;
    }

    private static function cString(string $field): string
    {
        $end = strpos($field, "\0");

        return $end === false ? $field : substr($field, 0, $end);
    }

    /**
     * @return array{path?: string, size?: int}
     */
    private static function paxRecords(string $data): array
    {
        $records = [];
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $space = strpos($data, ' ', $offset);
            $recordLength = $space === false ? '' : substr($data, $offset, $space - $offset);
            if (! ctype_digit($recordLength) || (int) $recordLength <= $space - $offset + 1 || $offset + (int) $recordLength > $length) {
                self::fail('The archive has damaged extended headers.');
            }
            $record = substr($data, $space + 1, $offset + (int) $recordLength - $space - 2);
            [$key, $value] = array_pad(explode('=', $record, 2), 2, '');
            if (str_starts_with($key, 'GNU.sparse')) {
                self::fail('The archive contains sparse files, which are not supported.');
            }
            if ($key === 'path') {
                if (str_contains($value, "\0")) {
                    self::fail('The archive has a path with a NUL byte.');
                }
                $records['path'] = $value;
            } elseif ($key === 'size') {
                if (! ctype_digit($value) || strlen($value) > 18) {
                    self::fail('The archive has damaged extended headers.');
                }
                $records['size'] = (int) $value;
            }
            $offset += (int) $recordLength;
        }

        return $records;
    }

    /**
     * @return list<string>
     */
    private static function pathSegments(string $path): array
    {
        if ($path === '' || strlen($path) > self::MAX_PATH_BYTES) {
            self::fail('The archive has an entry with an empty or too long path.');
        }
        if (str_contains($path, "\0")) {
            self::fail('The archive has a path with a NUL byte.');
        }
        if (str_starts_with($path, '/')) {
            self::fail('The archive entry '.self::display($path).' has an absolute path. Only relative paths are allowed.');
        }
        $segments = explode('/', $path);
        if (in_array('..', $segments, true)) {
            self::fail('The archive entry '.self::display($path).' points outside the site with "..".');
        }

        return $segments;
    }

    /**
     * Reads the data of an entry and its padding to the next block. Returns the data when $keep is true.
     *
     * @param  resource  $stream
     */
    private static function readData($stream, int $size, bool $keep = false): string
    {
        $data = '';
        $remaining = $size + ((self::BLOCK - $size % self::BLOCK) % self::BLOCK);
        while ($remaining > 0) {
            $chunk = gzread($stream, min($remaining, 1024 * 1024));
            if ($chunk === false || $chunk === '') {
                self::fail('The archive is truncated.');
            }
            $remaining -= strlen($chunk);
            if ($keep) {
                $data .= $chunk;
            }
        }

        return $keep ? substr($data, 0, $size) : '';
    }

    private static function display(string $path): string
    {
        return '"'.Str::limit((string) preg_replace('/[^\x20-\x7E]/', '?', $path), 120).'"';
    }

    private static function fail(string $message, string $key = 'archive'): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
