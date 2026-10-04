<?php

namespace App\Actions\Sentinel;

use App\Jobs\DistributeFluxTrustBundleJob;
use App\Models\FluxCaRotation;
use App\Models\FluxCertificate;
use App\Models\FluxCertificateAuthority;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Staged, no-downtime rotation of the instance-wide Flux CA.
 *
 * 1. start: create a pending CA and publish bundle N+1 (old + new CA).
 * 2. advance from distributing: once every usable Node acknowledged N+1 (or
 *    with force), issue a Flux leaf from the new CA through the renewal
 *    restart/validate/rollback path and make the new CA active.
 * 3. advance from switched: publish bundle N+2 (new CA only).
 * 4. advance from retiring: once every usable Node acknowledged N+2 (or with
 *    force), retire the old CA.
 *
 * Before the switch, cancel publishes bundle N+2 (old CA only) and discards
 * the new CA; advance from cancelling then finishes the cancel. Every step
 * re-reads the durable state, so a failed or interrupted step can be retried.
 */
class RotateFluxCertificateAuthority
{
    use AsAction;

    private const LOCK = 'flux:ca-rotation';

    public function current(): ?FluxCaRotation
    {
        return FluxCaRotation::query()->whereIn('status', FluxCaRotation::IN_PROGRESS)->latest('id')->first();
    }

    public function start(?User $user = null): FluxCaRotation
    {
        $rotation = $this->locked(function () use ($user): FluxCaRotation {
            EnsureFluxCertificateAuthority::run();

            return (new FluxCaRotation)->getConnection()->transaction(function () use ($user): FluxCaRotation {
                InstanceSettings::query()->lockForUpdate()->findOrFail(0);
                if ($this->current() !== null) {
                    throw new RuntimeException('A Flux CA rotation is already in progress.');
                }
                $currentVersion = ResolveFluxTrustBundle::run()['version'];
                $previous = FluxCertificateAuthority::query()->where('state', FluxCertificateAuthority::STATE_ACTIVE)->sole();
                $next = EnsureFluxCertificateAuthority::make()->createPending();

                return FluxCaRotation::query()->create([
                    'from_certificate_authority_id' => $previous->id,
                    'to_certificate_authority_id' => $next->id,
                    'status' => FluxCaRotation::STATUS_DISTRIBUTING,
                    'dual_bundle_version' => $currentVersion + 1,
                    'started_by' => $user?->id,
                ]);
            }, 3);
        });
        DistributeFluxTrustBundleJob::dispatch();

        return $rotation;
    }

    /**
     * @param  Closure(FluxCertificate): void|null  $restartAndValidate  Overrides the Flux restart and TLS check during the switch.
     */
    public function advance(bool $force = false, ?Closure $restartAndValidate = null): FluxCaRotation
    {
        return $this->locked(function () use ($force, $restartAndValidate): FluxCaRotation {
            $rotation = $this->current() ?? throw new RuntimeException('No Flux CA rotation is in progress.');

            return match ($rotation->status) {
                FluxCaRotation::STATUS_DISTRIBUTING => $this->switchAuthority($rotation, $force, $restartAndValidate),
                FluxCaRotation::STATUS_SWITCHED => $this->startRetirement($rotation),
                FluxCaRotation::STATUS_RETIRING, FluxCaRotation::STATUS_CANCELLING => $this->finish($rotation, $force),
            };
        });
    }

    public function cancel(): FluxCaRotation
    {
        $rotation = $this->locked(function (): FluxCaRotation {
            $rotation = $this->current() ?? throw new RuntimeException('No Flux CA rotation is in progress.');
            if ($rotation->status !== FluxCaRotation::STATUS_DISTRIBUTING) {
                throw new RuntimeException('The new Flux CA already signs the Flux certificate. Finish the rotation instead.');
            }

            $rotation->getConnection()->transaction(function () use ($rotation): void {
                $this->eraseKey($rotation->toAuthority, FluxCertificateAuthority::STATE_DISCARDED);
                $rotation->update([
                    'status' => FluxCaRotation::STATUS_CANCELLING,
                    'final_bundle_version' => $rotation->dual_bundle_version + 1,
                    'cancelled_at' => now(),
                    'last_error' => null,
                ]);
            });

            return $rotation;
        });
        DistributeFluxTrustBundleJob::dispatch();

        return $rotation;
    }

    /**
     * Usable Nodes that have not acknowledged the bundle the next step needs.
     *
     * @return Collection<int, Node>
     */
    public function blockingNodes(FluxCaRotation $rotation): Collection
    {
        if ($rotation->status === FluxCaRotation::STATUS_SWITCHED || ! $rotation->isInProgress()) {
            return collect();
        }

        return Node::query()
            ->where('is_usable', true)
            ->where(fn ($query) => $query->whereNull('flux_trust_bundle_version')->orWhere('flux_trust_bundle_version', '<', $rotation->targetBundleVersion()))
            ->orderBy('name')
            ->get();
    }

    /**
     * A public summary of the rotation and per-Node acknowledgements. It never contains keys or certificates.
     *
     * @return array{bundle_version: int, rotation: array<string, mixed>|null, next_step: string|null, nodes: list<array<string, mixed>>, blocking: int}
     */
    public function status(): array
    {
        $bundleVersion = ResolveFluxTrustBundle::run()['version'];
        $rotation = $this->current() ?? FluxCaRotation::query()->latest('id')->first();
        $target = $rotation?->isInProgress() ? $rotation->targetBundleVersion() : $bundleVersion;
        $blockingIds = $rotation === null ? [] : $this->blockingNodes($rotation)->pluck('id')->all();

        $nodes = Node::query()->with('team:id,name')->orderBy('name')->get()->map(fn (Node $node): array => [
            'uuid' => $node->uuid,
            'name' => $node->name,
            'team' => $node->team?->name,
            'is_usable' => (bool) $node->is_usable,
            'connected' => $node->hasRecentFluxHeartbeat(),
            'supports_update' => $node->supportsCapability(DistributeFluxTrustBundle::CAPABILITY),
            'version' => $node->flux_trust_bundle_version,
            'acknowledged' => $node->flux_trust_bundle_version !== null && $node->flux_trust_bundle_version >= $target,
            'acknowledged_at' => $node->flux_trust_bundle_acknowledged_at?->toIso8601String(),
            'error' => $node->flux_trust_bundle_error,
            'blocking' => in_array($node->id, $blockingIds, true),
        ])->values()->all();

        return [
            'bundle_version' => $bundleVersion,
            'rotation' => $rotation === null ? null : [
                'uuid' => $rotation->uuid,
                'status' => $rotation->status,
                'in_progress' => $rotation->isInProgress(),
                'target_bundle_version' => $rotation->targetBundleVersion(),
                'dual_bundle_version' => $rotation->dual_bundle_version,
                'final_bundle_version' => $rotation->final_bundle_version,
                'from_fingerprint' => $rotation->fromAuthority?->fingerprint,
                'to_fingerprint' => $rotation->toAuthority?->fingerprint,
                'switch_forced' => $rotation->switch_forced,
                'completion_forced' => $rotation->completion_forced,
                'last_error' => $rotation->last_error,
                'started_at' => $rotation->created_at?->toIso8601String(),
                'switched_at' => $rotation->switched_at?->toIso8601String(),
                'completed_at' => $rotation->completed_at?->toIso8601String(),
            ],
            'next_step' => match ($rotation?->isInProgress() ? $rotation->status : null) {
                FluxCaRotation::STATUS_DISTRIBUTING => 'switch',
                FluxCaRotation::STATUS_SWITCHED => 'retire',
                FluxCaRotation::STATUS_RETIRING => 'complete',
                FluxCaRotation::STATUS_CANCELLING => 'finish_cancel',
                default => null,
            },
            'nodes' => $nodes,
            'blocking' => count($blockingIds),
        ];
    }

    private function switchAuthority(FluxCaRotation $rotation, bool $force, ?Closure $restartAndValidate): FluxCaRotation
    {
        $blocking = $this->ensureNotBlocked($rotation, $force, 'switch to the new CA');
        $next = $rotation->toAuthority;
        $previous = $rotation->fromAuthority;

        try {
            $activeLeaf = FluxCertificate::query()->where('state', 'active')->first();
            // A previous attempt may have activated the new leaf before it could record the switch.
            if ($activeLeaf !== null && $activeLeaf->certificate_authority_id !== $next->id
                && ! RenewFluxCertificate::run($restartAndValidate, true, $next)) {
                throw new RuntimeException('A Flux certificate renewal is already running. Try again shortly.');
            }
        } catch (Throwable $exception) {
            $rotation->update(['last_error' => Str::limit($exception->getMessage(), 1000)]);
            throw $exception;
        }

        $rotation->getConnection()->transaction(function () use ($rotation, $previous, $next, $blocking): void {
            $previous->update(['state' => FluxCertificateAuthority::STATE_SUPERSEDED]);
            $next->update(['state' => FluxCertificateAuthority::STATE_ACTIVE]);
            $rotation->update([
                'status' => FluxCaRotation::STATUS_SWITCHED,
                'switched_at' => now(),
                'switch_forced' => $blocking > 0,
                'last_error' => null,
            ]);
        });

        return $rotation;
    }

    private function startRetirement(FluxCaRotation $rotation): FluxCaRotation
    {
        $rotation->update([
            'status' => FluxCaRotation::STATUS_RETIRING,
            'final_bundle_version' => $rotation->dual_bundle_version + 1,
            'retirement_started_at' => now(),
            'last_error' => null,
        ]);
        DistributeFluxTrustBundleJob::dispatch();

        return $rotation;
    }

    private function finish(FluxCaRotation $rotation, bool $force): FluxCaRotation
    {
        $retiring = $rotation->status === FluxCaRotation::STATUS_RETIRING;
        $blocking = $this->ensureNotBlocked($rotation, $force, $retiring ? 'retire the old CA' : 'finish the cancel');

        $rotation->getConnection()->transaction(function () use ($rotation, $retiring, $blocking): void {
            if ($retiring) {
                $this->eraseKey($rotation->fromAuthority, FluxCertificateAuthority::STATE_RETIRED);
            }
            $rotation->update([
                'status' => $retiring ? FluxCaRotation::STATUS_COMPLETED : FluxCaRotation::STATUS_CANCELLED,
                'completed_at' => now(),
                'completion_forced' => $blocking > 0,
                'last_error' => null,
            ]);
        });

        return $rotation;
    }

    /**
     * Erases a CA key without reading it. Saving the model would decrypt the stored key to
     * compare it, which fails for a key encrypted with a previous application key.
     */
    private function eraseKey(FluxCertificateAuthority $authority, string $state): void
    {
        FluxCertificateAuthority::query()->whereKey($authority->id)->update([
            'state' => $state,
            'private_key_pem' => Crypt::encryptString(''),
        ]);
    }

    private function ensureNotBlocked(FluxCaRotation $rotation, bool $force, string $step): int
    {
        $blocking = $this->blockingNodes($rotation);
        if ($blocking->isNotEmpty() && ! $force) {
            throw new RuntimeException(sprintf(
                'Cannot %s: %d Node(s) have not acknowledged trust bundle %d: %s. Wait for them to reconnect, or force this step and repair trust on them over SSH.',
                $step,
                $blocking->count(),
                $rotation->targetBundleVersion(),
                $blocking->take(10)->pluck('name')->implode(', ').($blocking->count() > 10 ? ', ...' : ''),
            ));
        }

        return $blocking->count();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function locked(Closure $callback): mixed
    {
        $lock = Cache::lock(self::LOCK, 900);
        if (! $lock->get()) {
            throw new RuntimeException('Another Flux CA rotation step is running. Try again shortly.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
