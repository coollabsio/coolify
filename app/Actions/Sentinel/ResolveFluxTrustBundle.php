<?php

namespace App\Actions\Sentinel;

use App\Models\FluxCaRotation;
use App\Models\FluxCertificate;
use App\Models\FluxCertificateAuthority;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Resolve the Flux CA trust bundle that Nodes must hold.
 *
 * Without a rotation the bundle is the active CA at the CA's version. A CA
 * rotation publishes later bundle versions: old + new CA, then the new CA
 * alone (or the old CA alone after a cancel). Bundle contents are public
 * certificates only.
 */
class ResolveFluxTrustBundle
{
    use AsAction;

    /**
     * @return array{version: int, certificate_pem: string, fingerprints: list<string>}
     */
    public function handle(): array
    {
        $rotation = FluxCaRotation::query()->latest('id')->first();
        if ($rotation === null) {
            $authority = EnsureFluxCertificateAuthority::run();

            return [
                'version' => $authority->version,
                'certificate_pem' => self::normalize($authority->certificate_pem),
                'fingerprints' => [$authority->fingerprint],
            ];
        }

        return self::forRotation($rotation, $rotation->targetBundleVersion());
    }

    /**
     * @return array{version: int, certificate_pem: string, fingerprints: list<string>}
     */
    public static function forRotation(FluxCaRotation $rotation, int $version): array
    {
        $authorities = FluxCertificateAuthority::query()
            ->whereIn('id', $rotation->trustedAuthorityIds())
            ->orderBy('version')
            ->get();
        if ($authorities->isEmpty() || $version < 1) {
            throw new RuntimeException('The Flux trust bundle cannot be resolved.');
        }

        return [
            'version' => $version,
            'certificate_pem' => $authorities->map(fn (FluxCertificateAuthority $authority): string => self::normalize($authority->certificate_pem))->implode(''),
            'fingerprints' => $authorities->pluck('fingerprint')->values()->all(),
        ];
    }

    /**
     * The lowest bundle version that still trusts the CA of the active Flux leaf.
     */
    public function minimumCompatibleVersion(): int
    {
        $authorityId = FluxCertificate::query()->where('state', 'active')->value('certificate_authority_id')
            ?? EnsureFluxCertificateAuthority::run()->id;
        $rotatedIn = FluxCaRotation::query()
            ->where('to_certificate_authority_id', $authorityId)
            ->latest('id')
            ->value('dual_bundle_version');
        if ($rotatedIn !== null) {
            return (int) $rotatedIn;
        }

        return (int) FluxCertificateAuthority::query()->whereKey($authorityId)->value('version');
    }

    /**
     * The trust bundle version for a Sentinel assignment.
     *
     * Sentinel refuses to connect when the assignment version differs from its
     * installed bundle. A Node whose installed bundle still trusts the served
     * Flux leaf gets its own version back, so it can connect and receive the
     * next bundle over Flux. Any other Node gets the current version and must
     * be repaired over SSH.
     */
    public function assignmentVersion(?int $installedVersion): int
    {
        $current = $this->handle()['version'];
        if ($installedVersion !== null
            && $installedVersion >= $this->minimumCompatibleVersion()
            && $installedVersion <= $current) {
            return $installedVersion;
        }

        return $current;
    }

    private static function normalize(string $certificate): string
    {
        return rtrim($certificate)."\n";
    }
}
