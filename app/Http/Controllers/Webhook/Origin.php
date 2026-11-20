<?php

namespace App\Http\Controllers\Webhook;

use App\Actions\Application\CleanupPreviewDeployment;
use App\Exceptions\InvalidWebhookPayloadException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Webhook\Concerns\DetectsSkipDeployCommits;
use App\Http\Controllers\Webhook\Concerns\ReadsWebhookPushPayload;
use App\Livewire\Source\Origin\Change as OriginSource;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\OriginApp;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Throwable;

class Origin extends Controller
{
    use DetectsSkipDeployCommits;
    use ReadsWebhookPushPayload;

    private const PREVIEW_DEPLOY_EVENTS = [
        'pull_request.created',
        'pull_request.head_ref.pushed',
        'pull_request.reopened',
        'pull_request.published',
    ];

    private const PREVIEW_CLEANUP_EVENTS = ['pull_request.closed', 'pull_request.merged'];

    /**
     * Install callback: Origin redirects here with a signed installation receipt.
     */
    public function install(Request $request)
    {
        try {
            $receipt = (string) $request->query('installation_receipt', '');
            if ($receipt === '') {
                return redirect()->route('source.all')->with('error', 'Invalid Origin install callback. Missing installation receipt.');
            }

            // The state claim selects the source; the receipt is verified against that source below.
            $state = rescue(fn () => (new Parser(new JoseEncoder))->parse($receipt)->claims()->get('state'), report: false);
            $payload = is_string($state) && $state !== '' ? Cache::pull(OriginSource::installStateCacheKey($state)) : null;
            $teamId = $request->user()?->currentTeam()?->id;
            if (! is_array($payload) || is_null($teamId) || (int) data_get($payload, 'team_id') !== (int) $teamId) {
                return redirect()->route('source.all')->with('error', 'Invalid or expired Origin install state. Please start the installation again.');
            }

            $originApp = OriginApp::whereKey(data_get($payload, 'origin_app_id'))->firstOrFail();
            if (! $request->user()->can('update', $originApp)) {
                return redirect()->route('source.all')->with('error', 'You are not authorized to connect this Origin App.');
            }

            try {
                $claims = verifyOriginInstallationReceipt($originApp, $receipt);
            } catch (Throwable $e) {
                return redirect()->route('source.origin.show', ['origin_app_uuid' => $originApp->uuid])->with('error', $e->getMessage());
            }

            $originApp->installation_id = $claims['installation_id'];
            $originApp->namespace_slug = rescue(
                fn () => data_get(originApi($originApp, '/app/installations/'.rawurlencode($claims['installation_id']), asApp: true), 'target.slug'),
                report: false,
            );
            $originApp->save();

            return redirect()->route('source.origin.show', ['origin_app_uuid' => $originApp->uuid]);
        } catch (Throwable $e) {
            return redirect()->route('source.all')->with('error', $e->getMessage());
        }
    }

    public function normal(Request $request)
    {
        $deliveryId = $request->header('webhook-id');
        $sources = OriginApp::query()
            ->where('app_id', (string) $request->header('webhook-app-id'))
            ->where('installation_id', (string) $request->header('webhook-installation-id'))
            ->get();

        if ($sources->isEmpty() || ! verifyOriginWebhookSignature(
            $sources->first()->api_url,
            $deliveryId,
            $request->header('webhook-timestamp'),
            $request->getContent(),
            $request->header('webhook-signature'),
        )) {
            auditLogWebhookFailure('origin', 'invalid_signature', [
                'event' => $request->header('webhook-event-type'),
            ]);

            return response(['status' => 'failed', 'message' => 'Invalid webhook signature.'], 401);
        }

        // The body is authoritative after verification. Headers only route the delivery.
        $envelope = json_decode($request->getContent(), true);
        if (! is_array($envelope)
            || data_get($envelope, 'appId') !== $request->header('webhook-app-id')
            || data_get($envelope, 'installationId') !== $request->header('webhook-installation-id')) {
            return response(['status' => 'failed', 'message' => 'Invalid webhook envelope.'], 400);
        }

        // Delivery is at least once. Retries keep the same webhook-id.
        $dedupeKey = 'origin-webhook-delivery:'.hash('sha256', $deliveryId);
        if (! Cache::add($dedupeKey, true, now()->addDay())) {
            return response(['status' => 'skipped', 'message' => 'Duplicate delivery.']);
        }

        try {
            $type = (string) data_get($envelope, 'event.type');
            $payload = data_get($envelope, 'event.payload', []);

            if ($type === 'installation.deleted') {
                $sources->each(fn (OriginApp $source) => $source->update(['installation_id' => null]));

                return response(['status' => 'success', 'message' => 'Installation removed.']);
            }

            if (str_starts_with($type, 'installation.')) {
                $slug = $this->webhookString(data_get($payload, 'installation.target.slug'));
                if ($slug) {
                    $sources->each(fn (OriginApp $source) => $source->update(['namespace_slug' => $slug]));
                }

                return response(['status' => 'success']);
            }

            $repository = $this->repositoryFullName($payload);
            if (! $repository) {
                return response(['status' => 'failed', 'message' => 'No repository found in the request.']);
            }

            $applications = Application::query()
                ->where('source_type', OriginApp::class)
                ->whereIn('source_id', $sources->pluck('id'))
                ->where('git_repository', $repository);

            $results = match (true) {
                $type === 'repository.pushed' => $this->handlePush($applications, $payload),
                in_array($type, [...self::PREVIEW_DEPLOY_EVENTS, ...self::PREVIEW_CLEANUP_EVENTS], true) => $this->handlePullRequest($type, $applications, $payload),
                default => null,
            };

            if ($results === null) {
                return response(['status' => 'skipped', 'message' => "Event {$type} is not used."]);
            }

            if ($results->contains('status', 'queue_full')) {
                Cache::forget($dedupeKey);

                return response($results->firstWhere('status', 'queue_full')['message'], 429)->header('Retry-After', 60);
            }

            return response($results);
        } catch (InvalidWebhookPayloadException $e) {
            return response(['status' => 'failed', 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            // Let Origin retry this delivery.
            Cache::forget($dedupeKey);

            return handleError($e);
        }
    }

    private function repositoryFullName(mixed $payload): ?string
    {
        $owner = $this->webhookString(data_get($payload, 'repository.owner.slug'));
        $name = $this->webhookString(data_get($payload, 'repository.name'));

        return $owner && $name ? "{$owner}/{$name}" : null;
    }

    private function handlePush($applications, mixed $payload): Collection
    {
        $results = collect();

        foreach (data_get($payload, 'refUpdates', []) as $index => $refUpdate) {
            $ref = data_get($refUpdate, 'ref');
            if (! is_string($ref) || ! str_starts_with($ref, 'refs/heads/') || data_get($refUpdate, 'deleted') === true) {
                continue;
            }

            $branch = $this->webhookPushBranch($ref);
            $commit = $this->webhookCommitSha($payload, "refUpdates.{$index}.after");
            $skipDeploy = self::shouldSkipDeploy([data_get($refUpdate, 'headCommit.message')]);

            foreach ((clone $applications)->where('git_branch', $branch)->get() as $application) {
                if (! $application->destination->server->isFunctional()) {
                    $results->push(['application' => $application->name, 'status' => 'failed', 'message' => 'Server is not functional']);

                    continue;
                }
                if (! $application->isDeployable()) {
                    $results->push(['application' => $application->name, 'status' => 'failed', 'message' => 'Deployments disabled']);

                    continue;
                }
                if ($skipDeploy) {
                    $results->push(['application' => $application->name, 'status' => 'skipped', 'message' => 'Commit contains [skip cd] or [skip ci].']);

                    continue;
                }

                $deploymentUuid = new_public_id();
                $result = queue_application_deployment(
                    application: $application,
                    deployment_uuid: $deploymentUuid,
                    commit: $commit ?? 'HEAD',
                    force_rebuild: false,
                    is_webhook: true,
                );

                auditLog('webhook.deployment.queued', [
                    'provider' => 'origin',
                    'mode' => 'app',
                    'application_uuid' => $application->uuid,
                    'application_name' => $application->name,
                    'deployment_uuid' => $deploymentUuid,
                    'commit' => $commit,
                ]);

                $results->push([
                    'application' => $application->name,
                    'status' => $result['status'] ?? 'success',
                    'message' => $result['message'] ?? 'Deployment queued.',
                ]);
            }
        }

        return $results;
    }

    private function handlePullRequest(string $type, $applications, mixed $payload): Collection
    {
        $results = collect();
        $baseBranch = $this->webhookPayloadString($payload, 'pullRequest.base.ref', required: true);
        $pullRequestId = $this->webhookPullRequestId($payload, 'pullRequest.number');
        $pullRequestUrl = $this->webhookPayloadUrl($payload, 'pullRequest.webUrl');
        $commit = $this->webhookCommitSha($payload, 'pullRequest.head.sha');
        $isDraft = data_get($payload, 'pullRequest.draft') === true;
        $skipDeploy = self::shouldSkipDeployAny([data_get($payload, 'pullRequest.title')]);

        foreach ((clone $applications)->where('git_branch', $baseBranch)->get() as $application) {
            $preview = ApplicationPreview::where('application_id', $application->id)
                ->where('pull_request_id', $pullRequestId)
                ->first();

            if (in_array($type, self::PREVIEW_CLEANUP_EVENTS, true)) {
                if ($preview) {
                    CleanupPreviewDeployment::run($application, $pullRequestId, $preview);
                    $results->push(['application' => $application->name, 'status' => 'success', 'message' => 'Preview deployment closed.']);
                }

                continue;
            }

            if (! $application->destination->server->isFunctional()) {
                $results->push(['application' => $application->name, 'status' => 'failed', 'message' => 'Server is not functional']);

                continue;
            }
            if (! $application->isPRDeployable()) {
                $results->push(['application' => $application->name, 'status' => 'failed', 'message' => 'Preview deployments disabled']);

                continue;
            }
            if ($isDraft || $skipDeploy) {
                $results->push(['application' => $application->name, 'status' => 'skipped', 'message' => $isDraft ? 'Draft pull request.' : 'PR title contains [skip cd] or [skip ci].']);

                continue;
            }

            if (! $preview) {
                $preview = ApplicationPreview::create([
                    'git_type' => 'origin',
                    'application_id' => $application->id,
                    'pull_request_id' => $pullRequestId,
                    'pull_request_html_url' => $pullRequestUrl ?? '',
                    ...($application->build_pack === 'dockercompose' ? ['docker_compose_domains' => $application->docker_compose_domains] : []),
                ]);
                $application->build_pack === 'dockercompose' ? $preview->generate_preview_fqdn_compose() : $preview->generate_preview_fqdn();
            }

            $result = queue_application_deployment(
                application: $application,
                pull_request_id: $pullRequestId,
                deployment_uuid: new_public_id(),
                commit: $commit ?? 'HEAD',
                force_rebuild: false,
                is_webhook: true,
                git_type: 'origin',
            );

            $results->push([
                'application' => $application->name,
                'status' => $result['status'] ?? 'success',
                'message' => $result['message'] ?? 'Preview Deployment queued',
            ]);
        }

        return $results;
    }
}
