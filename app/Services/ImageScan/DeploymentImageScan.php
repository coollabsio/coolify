<?php

namespace App\Services\ImageScan;

use App\Actions\ImageScan\RefreshCandidates;
use App\Actions\ImageScan\ScanImage;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ImageScanErrorCode;
use App\Enums\ImageScanGateMode;
use App\Enums\ImageScanStatus;
use App\Exceptions\DeploymentException;
use App\Jobs\RefreshCandidatesJob;
use App\Jobs\ScanResourceImagesJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ImageScan;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * The image scan steps of one application deployment: the deploy gate (spec 15.8), the start pins of security
 * updates, and the scan after the start. `ApplicationDeploymentJob` creates one per job and calls it at fixed points.
 *
 * The job changes its servers and Compose file while it deploys, so this class reads them from the job when a step
 * needs them, and writes the Compose file back through the job.
 */
final class DeploymentImageScan
{
    private ?ResolvedImageScanPolicy $policy = null;

    private ?int $policyServerId = null;

    private ?bool $rollbackRanBefore = null;

    /** @var array<string, mixed>|null The worst gate result of this deployment (blocked, then error, then pass). */
    private ?array $gateResult = null;

    /** The gate decided for the whole deployment (bypass, rollback, or a scan that cannot run). */
    private bool $gateSettled = false;

    /** @var array<string, true> Images whose scan ran or was reused before the start, so they need no scan after it. */
    private array $scannedImages = [];

    /** Repo digest reference that replaces the image tag in the compose file. */
    private ?string $pinnedImage = null;

    /** Whether the scanned build of a "Pull latest" security update has the image tag now, so no pull may replace it. */
    private bool $startPinned = false;

    /** The base image of the final stage of a Dockerfile build (spec 7.4). */
    private ?string $baseImageRef = null;

    /** @var array<string, true> Compose images that the gate checked on the server. */
    private array $checkedImages = [];

    /** @var array<int, true> IDs of stored scans that the gate reused; they join this deployment when it finishes. */
    private array $reusedScans = [];

    /** @var list<string>|null Image IDs that the gate blocked in earlier deployments, read once per job. */
    private ?array $blockedImageIds = null;

    /**
     * @param  Closure(string): mixed  $read  Reads a job property by name, when the step runs.
     * @param  Closure(string, string): mixed  $write  Sets `docker_compose` or `generated_docker_compose` on the job.
     * @param  Closure(array<int|string, mixed> ...): mixed  $run  The job's `execute_remote_command()`.
     * @param  Closure(): void  $checkForCancellation
     */
    public function __construct(
        private readonly Application $application,
        private readonly ApplicationDeploymentQueue $deployment,
        private readonly Closure $read,
        private readonly Closure $write,
        private readonly Closure $run,
        private readonly Closure $checkForCancellation,
    ) {}

    /** The repo digest that the start uses instead of the tag, set by the gate. */
    public function pinnedImage(): ?string
    {
        return $this->pinnedImage;
    }

    /** The scanned build of a "Pull latest" security update has the tag, so the start must not pull it. */
    public function startPinned(): bool
    {
        return $this->startPinned;
    }

    public function baseImageRef(): ?string
    {
        return $this->baseImageRef;
    }

    /**
     * The gate scanned the new image while the deployment was in progress, so its stored security status compared it
     * with the image of the previous deployment. Now that this deployment finished, the status is stored again.
     */
    public function refreshSecurity(): void
    {
        if ($this->job('pull_request_id') !== 0 || ! ImageScanPolicyResolver::isScanOn($this->application)) {
            return;
        }

        try {
            ResourceScans::refreshSummaries(ResourceScans::currentFor($this->application));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The compose file from Git decides which image overrides of security updates still apply. A preview deploys
     * the compose file of its branch, so it keeps them.
     */
    public function forgetStaleComposeImageOverrides(): void
    {
        if ($this->job('pull_request_id') !== 0) {
            return;
        }

        try {
            $this->application->forgetStaleComposeImageOverrides();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The deploy gate (spec 15.8): stops the start of an image with security problems, by the "Block deploys" mode.
     * Does nothing, and runs no remote command, unless the gate applies to this application.
     */
    public function checkGate(string $imageRef): void
    {
        if ($this->gateSettled || ! $this->gateApplies()) {
            return;
        }

        ($this->checkForCancellation)();

        if ($this->settleGateForDeployment()) {
            $this->pinBypassedImage();

            return;
        }

        if ($this->application->build_pack === 'dockerimage' && ! $this->startPinned) {
            $this->deployment->addLogEntry("Pulling image ({$imageRef}) to scan it before the start.");
            ($this->run)([
                serverDockerConfigPrefix($this->job('server')).'docker pull '.escapeshellarg($imageRef),
                'hidden' => true,
            ]);
        }

        $image = $this->inspectImageForScan($imageRef);

        if ($image !== null && $this->job('rollback') && $this->imageRanBefore($image['id'])) {
            $this->deployment->addLogEntry('Rollback to an image that ran before. The image scan does not block it.');

            return;
        }

        if ($image !== null) {
            $this->checkedImages[$imageRef] = true;
            $this->pinScannedImage($image['repo_digest']);
        }

        $error = $this->errorBeforeScan();
        if ($error !== null) {
            $this->gateSettled = true;
            $this->applyGate(null, ...$error);

            return;
        }

        // Read before the scan: a scan of the same image takes over the row of the running image.
        $running = $this->runningImageScan($imageRef);
        $baselineMissing = false;
        if ($running === null && $this->hasEarlierFinishedDeployment()) {
            $running = $this->latestEarlierImageScan($imageRef, $image['id'] ?? null);
            // An image the gate blocked before is never its own baseline.
            $baselineMissing = $running === null && ! ($image !== null && in_array($image['id'], $this->blockedImageIds(), true));
        }
        $reused = $image !== null ? $this->completedImageScan($image['id']) : null;
        $scan = $reused ?? ScanImage::run($this->job('server'), $this->application, $imageRef, $this->deployment, lockWaitSeconds: ScanImage::GATE_LOCK_WAIT_SECONDS, baseImageRef: $this->baseImageRef, queueCandidateCheck: false);
        $this->queueCandidates($scan);

        $this->applyGate($scan, imageRef: $imageRef, repoDigest: $image['repo_digest'] ?? null, running: $running, baselineMissing: $baselineMissing, imageId: $image['id'] ?? $scan->image_id);

        if ($reused !== null) {
            $this->reusedScans[(int) $reused->id] = true;
        }
    }

    /**
     * Gates every image of a Compose deployment before the running containers stop, and keeps Compose from pulling
     * a different image for a checked service.
     */
    public function checkComposeGate(): void
    {
        if (! $this->gateApplies()) {
            return;
        }

        if ($this->usesCustomComposeCommand()) {
            // The command can build or pull other images than the Compose file names, so no image is checked.
            if (! $this->gateSettled && ! $this->settleGateForDeployment()) {
                $this->gateSettled = true;
                $this->applyGate(null, ...$this->errorBeforeScan());
            }

            return;
        }

        $services = collect(data_get(Yaml::parse((string) $this->job('generated_docker_compose')), 'services', []));
        $images = $services->map(fn ($service) => data_get($service, 'image'))->filter(fn ($image) => is_string($image) && $image !== '')->unique();

        foreach ($images as $image) {
            $this->checkGate($image);
        }

        if (! $this->gateSettled && $services->contains(fn ($service) => ! is_string(data_get($service, 'image')))) {
            // A raw Compose service without an image gets a name that Coolify does not know.
            $this->gateSettled = true;
            $this->applyGate(null, ImageScanErrorCode::Unknown);
        }

        if ($this->checkedImages === [] || $this->application->settings->is_raw_compose_deployment_enabled) {
            return;
        }

        $compose = Yaml::parse((string) $this->job('generated_docker_compose'));
        foreach ($compose['services'] ?? [] as $name => $service) {
            if (isset($this->checkedImages[(string) data_get($service, 'image')])) {
                $compose['services'][$name]['pull_policy'] = 'never';
            }
        }

        $yaml = Yaml::dump($compose, 10);
        ($this->write)('generated_docker_compose', $yaml);
        $this->writeComposeFileToWorkdir($yaml);
    }

    /**
     * The gate runs only when the instance allows scanning, the application scan is on, and its rules block deploys.
     */
    public function gateApplies(): bool
    {
        // Scanning off on the server still gates: the scan cannot run, and "If the scan cannot run" decides.
        return ImageScanPolicyResolver::isAllowedFor($this->application)
            && $this->policy()->gateMode !== ImageScanGateMode::Off->value;
    }

    /**
     * The stored scans that the gate reused belong to this deployment once it finished, so the current scans of the
     * application (all images of a Compose deployment) include them. Until then they stay with the deployment that
     * ran them: a deployment that is running or failed never takes them over. Preview deployments keep the link of
     * the application. Called right after the deployment is marked finished (`post_deployment()`, `just_restart()`).
     */
    public function linkReusedScans(): void
    {
        if ($this->job('pull_request_id') !== 0 || $this->reusedScans === []) {
            return;
        }

        // completeDeployment() does nothing when the deployment was cancelled or failed meanwhile.
        if (ApplicationDeploymentQueue::query()->whereKey($this->deployment->id)->value('status') !== ApplicationDeploymentStatus::FINISHED->value) {
            return;
        }

        try {
            $this->application->imageScans()
                ->whereKey(array_keys($this->reusedScans))
                ->where(fn ($query) => $query
                    ->whereNull('deployment_queue_id')
                    ->orWhere('deployment_queue_id', '!=', $this->deployment->id))
                ->update(['deployment_queue_id' => $this->deployment->id]);
            $this->reusedScans = [];
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A "Pull latest" security update of a Docker image app: pulls the build that Coolify scanned and gives it the
     * configured tag, so the gate and the start use it and do not pull the tag again.
     */
    public function applyStartPin(): void
    {
        if ($this->application->build_pack !== 'dockerimage' || $this->job('pull_request_id') !== 0 || $this->job('rollback')) {
            return;
        }

        $imageName = $this->job('production_image_name');
        $server = $this->job('server');
        $pins = ImageStartPin::for(ImageStartPin::take($this->application, $server->id), [$imageName]);
        if ($pins === []) {
            return;
        }

        $this->deployment->addLogEntry("Pulling the scanned build of {$imageName}.");
        ($this->run)(...array_map(fn (string $command): array => [$command, 'hidden' => true],
            ImageStartPin::commands($pins, serverDockerConfigPrefix($server))));
        $this->startPinned = true;
    }

    /**
     * A "Pull latest" security update of a Compose app: after the pull of the registry images, gives the scanned builds
     * their tags again. `up` uses local images, so the containers run the scanned builds.
     */
    public function applyComposeStartPins(): void
    {
        if ($this->job('pull_request_id') !== 0 || $this->job('docker_compose_custom_build_command')) {
            return;
        }

        $server = $this->job('server');
        $pins = ImageStartPin::take($this->application, $server->id);
        if ($pins === []) {
            return;
        }

        $this->deployment->addLogEntry('Pulling the scanned builds of the security update.');
        ($this->run)(...array_map(fn (string $command): array => [$command, 'hidden' => true],
            ImageStartPin::commands($pins, serverDockerConfigPrefix($server))));
    }

    /**
     * Remembers the base image of the final stage of a Dockerfile build for the image scan (spec 7.4). Runs no remote
     * command and never fails the deployment.
     */
    public function rememberDockerfileBaseImage(): void
    {
        $this->baseImageRef = null;

        try {
            if ($this->application->build_pack !== 'dockerfile'
                || ! ImageScanPolicyResolver::isAllowedFor($this->application)
                || (ImageScanPolicyResolver::offState($this->application, $this->job('server')) !== null
                    && ImageScanPolicyResolver::offState($this->application, $this->job('mainServer')) !== null)) {
                return;
            }

            $dockerfile = filled($this->application->dockerfile) ? (string) $this->application->dockerfile : (string) $this->job('saved_outputs')->get('dockerfile_from_repo');
            $this->baseImageRef = DockerfileBaseImage::finalStage($dockerfile, $this->application->dockerfile_target_build);
        } catch (Throwable $e) {
            Log::warning("Deployment {$this->job('deployment_uuid')} could not read the base image for the image scan: {$e->getMessage()}");
        }
    }

    /**
     * Queues one scan of the started images when the gate did not scan them before the start. Restarts, skipped
     * builds, and additional servers run an image that was scanned before, so they get no scan (spec 4.4), except
     * for a rollback.
     */
    public function dispatchScanAfterStart(): void
    {
        try {
            if ((($this->job('skip_build') || $this->job('is_this_additional_server')) && ! $this->job('rollback'))
                || ImageScanPolicyResolver::offState($this->application, $this->job('mainServer')) !== null) {
                return;
            }

            // Images that the gate scanned before the start need no second scan; the others (a scan that could
            // not run, a rollback image that ran before) are scanned now.
            if ($this->application->build_pack === 'dockercompose') {
                $images = collect(data_get(Yaml::parse((string) $this->job('generated_docker_compose')), 'services', []))
                    ->map(fn ($service) => data_get($service, 'image'))
                    ->filter(fn ($image) => is_string($image) && $image !== '' && ! isset($this->scannedImages[$image]))
                    ->unique()
                    ->values()
                    ->all();
            } else {
                $imageName = $this->job('production_image_name');
                $images = $imageName !== null && ! isset($this->scannedImages[$imageName])
                    ? [$this->pinnedImage ?? $imageName]
                    : [];
            }

            if ($images !== []) {
                ScanResourceImagesJob::dispatch($this->application, $this->deployment->id, $images, false, $this->baseImageRef);
            }
        } catch (Throwable $e) {
            Log::warning("Deployment {$this->job('deployment_uuid')} could not queue the image scan: {$e->getMessage()}");
        }
    }

    private function job(string $property): mixed
    {
        return ($this->read)($property);
    }

    /**
     * Writes the Compose file again after the gate changed it, with the same commands as the first write.
     */
    private function writeComposeFileToWorkdir(string $yaml): void
    {
        $uuid = $this->job('deployment_uuid');
        $path = $this->job('workdir').$this->job('docker_compose_location');

        ($this->run)([
            "docker exec {$uuid} rm -f {$path}",
            'hidden' => true,
        ], [
            "docker exec -i {$uuid} tee {$path} > /dev/null",
            'input' => $yaml,
            'hidden' => true,
        ]);
    }

    /**
     * Queues the check of newer versions and of the base image for the scanned image. The gate does not wait for it:
     * it reads only the findings.
     */
    private function queueCandidates(ImageScan $scan): void
    {
        try {
            $scan->setRelation('resource', $this->application);
            if ($scan->exists && RefreshCandidates::shouldRefresh($scan)) {
                RefreshCandidatesJob::dispatch($scan);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The scan of the image the application ran before this deployment: a completed scan of the latest finished
     * deployment that is not a preview. The deployment decides, not the scan time: a redeploy of an older image links
     * its older scan to the newer deployment. A Compose application matches the image by name. Null before the first
     * deploy, so every finding of the new image counts as new.
     */
    private function runningImageScan(string $imageRef): ?ImageScan
    {
        $query = fn () => $this->application->imageScans()
            ->where('status', ImageScanStatus::Completed)
            ->whereHas('deploymentQueue', fn ($query) => $query
                ->where('pull_request_id', 0)
                ->where('status', ApplicationDeploymentStatus::FINISHED->value))
            ->orderByDesc('deployment_queue_id')
            ->orderByDesc('scanned_at')
            ->orderByDesc('id');

        if ($this->application->build_pack !== 'dockercompose') {
            return $query()->first();
        }

        $name = ScanImage::imageName($imageRef);
        $id = $query()->get(['id', 'image_ref'])
            ->first(fn (ImageScan $scan): bool => ScanImage::imageName((string) $scan->image_ref) === $name)?->id;

        return $id === null ? null : ImageScan::query()->find($id);
    }

    /**
     * A finished deployment of this application that is not a preview ran before this one, so this is not the first
     * deploy.
     */
    private function hasEarlierFinishedDeployment(): bool
    {
        return ApplicationDeploymentQueue::query()
            ->where('application_id', (string) $this->application->id)
            ->where('pull_request_id', 0)
            ->where('status', ApplicationDeploymentStatus::FINISHED->value)
            ->whereKeyNot($this->deployment->id)
            ->exists();
    }

    /**
     * The gate's baseline when no scan of the running image exists although the app was deployed before (for
     * example, scanning was turned on after the deploy): the newest completed scan that is not a preview. The scan of
     * an image the gate blocked never counts, and neither does the image of this deploy, so trying a blocked image
     * again cannot pass.
     */
    private function latestEarlierImageScan(string $imageRef, ?string $imageId): ?ImageScan
    {
        $name = $this->application->build_pack === 'dockercompose' ? ScanImage::imageName($imageRef) : null;
        $excluded = array_values(array_unique(array_filter([...$this->blockedImageIds(), $imageId])));

        return $this->application->imageScans()
            ->where('status', ImageScanStatus::Completed)
            ->where(fn ($query) => $query
                ->whereNull('deployment_queue_id')
                ->orWhereHas('deploymentQueue', fn ($deployment) => $deployment
                    ->where('pull_request_id', 0)
                    ->whereKeyNot($this->deployment->id)))
            ->whereDoesntHave('deploymentQueue', fn ($deployment) => $deployment->where('scan_gate_result->blocked', true))
            ->when($excluded !== [], fn ($query) => $query->whereNotIn('image_id', $excluded))
            ->orderByDesc('scanned_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->first(fn (ImageScan $scan): bool => $name === null || ScanImage::imageName((string) $scan->image_ref) === $name);
    }

    /**
     * The images that the gate blocked in an earlier deployment of this application that is not a preview: the image
     * ID stored with the result, or a scan linked to the blocked deployment. The stored ID keeps the block when the
     * scan was removed.
     *
     * @return list<string>
     */
    private function blockedImageIds(): array
    {
        if ($this->blockedImageIds !== null) {
            return $this->blockedImageIds;
        }

        $blocked = fn ($query) => $query
            ->where('pull_request_id', 0)
            ->whereKeyNot($this->deployment->id)
            ->where('scan_gate_result->blocked', true);

        $stored = $blocked(ApplicationDeploymentQueue::query()->where('application_id', (string) $this->application->id))
            ->get(['id', 'scan_gate_result'])
            ->map(fn (ApplicationDeploymentQueue $deployment) => data_get($deployment->scan_gate_result, 'image_id'));
        $scanned = $this->application->imageScans()
            ->whereNotNull('image_id')
            ->whereHas('deploymentQueue', $blocked)
            ->pluck('image_id');

        return $this->blockedImageIds = $stored->merge($scanned)
            ->filter(fn ($id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * "Deploy anyway" of a Docker image starts the image that was blocked, not a newer image behind the same tag.
     */
    private function pinBypassedImage(): void
    {
        if ($this->application->build_pack !== 'dockerimage' || $this->gateBypassedBy() === null) {
            return;
        }

        $queue = $this->deployment;
        $pinned = data_get($queue->scan_gate_result, 'repo_digest');
        if (! is_string($pinned) && filled($queue->parent_deployment_uuid)) {
            $pinned = data_get(ApplicationDeploymentQueue::query()
                ->where('deployment_uuid', $queue->parent_deployment_uuid)
                ->where('application_id', $queue->application_id)
                ->value('scan_gate_result'), 'repo_digest');
        }
        if (! is_string($pinned)) {
            return;
        }

        try {
            $ref = ImageRef::parse($pinned);
            $configured = ImageRef::parse($this->job('production_image_name'));
        } catch (InvalidArgumentException) {
            return;
        }

        if ($ref->digest === null || $ref->qualifiedName() !== $configured->qualifiedName()) {
            return;
        }

        if (! $this->pinCompose((string) $ref)) {
            return;
        }

        $this->deployment->addLogEntry("Image scan: the deployment starts the blocked image {$ref}.");
    }

    /**
     * Starts the scanned image where the start could pull another one: a Docker image, an image from a build
     * server, or a Swarm service. Without a repo digest the image stays as it is.
     */
    private function pinScannedImage(?string $repoDigest): void
    {
        if ($repoDigest === null || $this->application->build_pack === 'dockercompose') {
            return;
        }

        if ($this->application->build_pack !== 'dockerimage' && ! $this->job('use_build_server') && ! $this->job('mainServer')->isSwarm()) {
            return;
        }

        if (! $this->pinCompose($repoDigest)) {
            return;
        }

        $this->deployment->addLogEntry("Image scan: the deployment starts {$repoDigest}.");
    }

    /**
     * Sets the image of the application service in the job's Compose file. False when the file has no such service.
     */
    private function pinCompose(string $image): bool
    {
        $compose = Yaml::parse((string) $this->job('docker_compose'));
        $container = $this->job('container_name');
        if (! isset($compose['services'][$container])) {
            return false;
        }

        $compose['services'][$container]['image'] = $image;
        $yaml = Yaml::dump($compose, 10);
        ($this->write)('generated_docker_compose', $yaml);
        ($this->write)('docker_compose', $yaml);
        $this->pinnedImage = $image;

        return true;
    }

    /**
     * The image is scanned on the build server when it is there, else on the main server. The rules always resolve
     * through the main destination server.
     */
    private function policy(): ResolvedImageScanPolicy
    {
        $server = $this->imageIsOnBuildServer() ? $this->job('build_server') : null;

        if ($this->policy === null || $this->policyServerId !== $server?->id) {
            $this->policy = ImageScanPolicyResolver::for($this->application, $server);
            $this->policyServerId = $server?->id;
        }

        return $this->policy;
    }

    /**
     * The image is on the build server when the deployment built there. A restart and a Docker image app keep the
     * image on the deployment server, even with a build server.
     */
    private function imageIsOnBuildServer(): bool
    {
        if (! $this->job('use_build_server')) {
            return false;
        }

        $buildServer = $this->job('build_server');

        return $buildServer !== null && $this->job('server')->is($buildServer);
    }

    private function usesCustomComposeCommand(): bool
    {
        return filled($this->job('docker_compose_custom_start_command')) || filled($this->job('docker_compose_custom_build_command'));
    }

    /**
     * Decisions for the whole deployment that need no image: "Deploy anyway", and a rollback to a commit that ran before.
     */
    private function settleGateForDeployment(): bool
    {
        $bypassedBy = $this->gateBypassedBy();
        if ($bypassedBy !== null) {
            $this->gateSettled = true;
            $this->logGateBypass($bypassedBy);

            return true;
        }

        if ($this->job('rollback') && $this->rollbackCommitRanBefore()) {
            $this->gateSettled = true;
            $this->deployment->addLogEntry('Rollback to an image that ran before. The image scan does not block it.');

            return true;
        }

        return false;
    }

    /**
     * The reason the gate cannot scan: scanning is off where the image is, or a custom Compose command.
     *
     * @return array{0: ImageScanErrorCode, 1: array{server: string}}|null
     */
    private function errorBeforeScan(): ?array
    {
        $policy = $this->policy();

        if (! $policy->scanEnabled) {
            $error = $this->imageIsOnBuildServer() ? ImageScanErrorCode::BuildServerOff : ImageScanErrorCode::ScanningOff;

            return [$error, ['server' => (string) ($policy->scanServer?->name ?? $this->job('server')->name)]];
        }

        if ($this->usesCustomComposeCommand()) {
            return [ImageScanErrorCode::CustomCommand, ['server' => (string) $this->job('server')->name]];
        }

        return null;
    }

    /**
     * A rollback is not gated when a finished deployment of this application ran its commit (spec 4.4). A local tag
     * alone does not count: the image of a blocked deployment stays tagged on the server.
     */
    private function rollbackCommitRanBefore(): bool
    {
        $commit = $this->job('commit');
        if (blank($commit) || $commit === 'HEAD') {
            return false;
        }

        return $this->rollbackRanBefore ??= ApplicationDeploymentQueue::query()
            ->where('application_id', (string) $this->application->id)
            ->where('status', ApplicationDeploymentStatus::FINISHED->value)
            ->where('commit', $commit)
            ->where('pull_request_id', $this->job('pull_request_id'))
            ->exists();
    }

    /**
     * An image ran before when a finished deployment of this application scanned it.
     */
    private function imageRanBefore(string $imageId): bool
    {
        return $this->application->imageScans()
            ->where('image_id', $imageId)
            ->whereHas('deploymentQueue', fn ($query) => $query->where('status', ApplicationDeploymentStatus::FINISHED->value))
            ->exists();
    }

    private function completedImageScan(string $imageId): ?ImageScan
    {
        return $this->application->imageScans()
            ->where('image_id', $imageId)
            ->where('status', ImageScanStatus::Completed)
            ->first();
    }

    /**
     * The local image ID and the registry digest reference of the image, read with one `docker image inspect`.
     * A locally built image (classic store, no push) has no repo digest.
     *
     * @return array{id: string, repo_digest: ?string}|null
     */
    private function inspectImageForScan(string $imageRef): ?array
    {
        try {
            $ref = ImageRef::parse($imageRef);
        } catch (InvalidArgumentException) {
            return null;
        }

        $format = escapeshellarg('{{.Id}} {{range .RepoDigests}}{{.}} {{end}}');
        ($this->run)([
            "docker image inspect --format {$format} ".escapeshellarg($imageRef).' 2>/dev/null',
            'hidden' => true,
            'ignore_errors' => true,
            'save' => 'image_scan_inspect',
            'append' => false,
        ]);

        $output = str($this->job('saved_outputs')->get('image_scan_inspect'))->trim()->value();
        $parts = preg_split('/\s+/', $output, -1, PREG_SPLIT_NO_EMPTY);
        $id = array_shift($parts);
        if (! is_string($id) || ! ImageRef::isValidDigest($id)) {
            return null;
        }

        $repoDigest = null;
        foreach ($parts as $candidate) {
            try {
                $parsed = ImageRef::parse($candidate);
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($parsed->digest !== null && $parsed->qualifiedName() === $ref->qualifiedName()) {
                $repoDigest = (string) $parsed;
                break;
            }
        }

        return ['id' => $id, 'repo_digest' => $repoDigest];
    }

    /**
     * Writes the result on the deployment, logs it, and stops the deployment when the gate blocks.
     *
     * @param  array<string, int|string>  $errorContext
     * @param  ImageScan|null  $running  The scan of the image the application ran before this deployment.
     * @param  bool  $baselineMissing  The app was deployed before, but Coolify has no earlier scan to compare with.
     * @param  string|null  $imageId  The local image ID, stored so a later deploy of a blocked image stays blocked.
     */
    private function applyGate(?ImageScan $scan, ?ImageScanErrorCode $error = null, array $errorContext = [], ?string $imageRef = null, ?string $repoDigest = null, ?ImageScan $running = null, bool $baselineMissing = false, ?string $imageId = null): void
    {
        $completed = $scan !== null && $scan->status === ImageScanStatus::Completed;

        if ($scan !== null && ! $completed && $error === null) {
            $error = $scan->error_code ?? ImageScanErrorCode::Unknown;
            $errorContext = (array) $scan->error_context;
        }

        $scan?->setRelation('resource', $this->application);
        $policy = $this->policy();
        $result = SecurityGate::evaluate($scan, $running, $policy->gateMode, IgnoreMatcher::forResource($this->application), $error, $errorContext, $policy->onScanError, $baselineMissing);
        $stored = SecurityGate::stored($result);
        if ($stored['blocked'] && $completed) {
            // The action that fixes the blocked image, for the gate popover.
            try {
                $action = SecurityCard::blockedImageAction($scan);
                if ($action !== null) {
                    $stored['action'] = $action;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        // A Compose deployment gates several images: keep the worst result (blocked, then error, then pass).
        $rank = fn (?array $gateResult): int => match (true) {
            $gateResult === null => -1,
            (bool) ($gateResult['blocked'] ?? false) => 2,
            ($gateResult['error'] ?? null) !== null => 1,
            default => 0,
        };
        if ($rank($stored) >= $rank($this->gateResult)) {
            // The digest lets "Deploy anyway" start the image that was blocked.
            $this->gateResult = [
                ...$stored,
                ...($repoDigest !== null ? ['repo_digest' => $repoDigest] : []),
                ...($imageId !== null && $imageId !== '' ? ['image_id' => $imageId] : []),
            ];
            $this->deployment->update(['scan_gate_result' => $this->gateResult]);
        }
        if ($result['scan_ran'] && $imageRef !== null) {
            $this->scannedImages[$imageRef] = true;
        }

        // A pass logs "passed" first; the lines after it warn about problems that the running image has too.
        $failed = $result['blocked'] || $result['error'] !== null;
        foreach (SecurityGate::logLines($result) as $index => $line) {
            $this->deployment->addLogEntry($line, $failed || $index > 0 ? 'stderr' : 'stdout');
        }

        if ($result['blocked']) {
            throw DeploymentException::alreadyLogged($result['headline']);
        }
    }

    /**
     * The user who chose "Deploy anyway" for this deployment, or for the main deployment that queued it.
     */
    private function gateBypassedBy(): ?int
    {
        $queue = $this->deployment;
        $userId = $queue->scan_gate_bypassed_by;

        if (blank($userId) && filled($queue->parent_deployment_uuid)) {
            $userId = ApplicationDeploymentQueue::query()
                ->where('deployment_uuid', $queue->parent_deployment_uuid)
                ->where('application_id', $queue->application_id)
                ->value('scan_gate_bypassed_by');
        }

        return filled($userId) ? (int) $userId : null;
    }

    private function logGateBypass(int $userId): void
    {
        $user = User::query()->find($userId);
        $name = $user?->name ?? 'a deleted user';

        $this->deployment->addLogEntry("Deploy anyway by {$name}.", 'stderr');

        auditLog('ui.application.image_scan_gate_bypassed', [
            'team_id' => $this->application->team()?->id,
            'application_uuid' => $this->application->uuid,
            'deployment_uuid' => $this->job('deployment_uuid'),
            'user_id' => $userId,
            'actor_id' => $user?->id,
            'actor_name' => $user?->name,
            'actor_email' => $user?->email,
        ]);
    }
}
