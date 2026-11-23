<?php

namespace App\Services\ImageScan;

use App\Models\ImageSbom;
use App\Models\ImageScan;
use Illuminate\Support\Str;

/**
 * SBOM downloads made only from the stored package list, so no image configuration (environment, history, labels)
 * can be in them.
 */
final class SbomDocument
{
    public const FORMATS = ['cyclonedx', 'spdx'];

    /**
     * CycloneDX 1.6 JSON.
     *
     * @return array<string, mixed>
     */
    public static function cycloneDx(ImageScan $scan, ImageSbom $sbom): array
    {
        $image = (string) $scan->image_ref;

        return [
            'bomFormat' => 'CycloneDX',
            'specVersion' => '1.6',
            'serialNumber' => 'urn:uuid:'.Str::uuid(),
            'version' => 1,
            'metadata' => [
                'timestamp' => now()->toIso8601ZuluString(),
                'tools' => ['components' => [['type' => 'application', 'name' => 'Coolify']]],
                'component' => [
                    'type' => 'container',
                    'bom-ref' => 'image',
                    'name' => $image,
                ],
            ],
            'components' => array_map(fn (Package $package): array => array_filter([
                'type' => 'library',
                'bom-ref' => 'package-'.$package->id,
                'name' => $package->name,
                'version' => $package->version !== '' ? $package->version : null,
                'purl' => $package->purl !== '' ? $package->purl : null,
                'licenses' => $package->licenses === [] ? null : array_map(
                    fn (string $license): array => ['license' => ['name' => $license]],
                    $package->licenses,
                ),
            ], fn ($value) => $value !== null), self::packages($sbom)),
        ];
    }

    /**
     * SPDX 2.3 JSON.
     *
     * @return array<string, mixed>
     */
    public static function spdx(ImageScan $scan, ImageSbom $sbom): array
    {
        $image = (string) $scan->image_ref;
        $packages = [];
        $relationships = [];

        foreach (self::packages($sbom) as $index => $package) {
            $spdxId = 'SPDXRef-Package-'.($index + 1);
            $packages[] = array_filter([
                'name' => $package->name,
                'SPDXID' => $spdxId,
                'versionInfo' => $package->version !== '' ? $package->version : null,
                'downloadLocation' => 'NOASSERTION',
                'filesAnalyzed' => false,
                'licenseConcluded' => 'NOASSERTION',
                'licenseDeclared' => self::licenseExpression($package->licenses),
                'copyrightText' => 'NOASSERTION',
                'externalRefs' => $package->purl === '' ? null : [[
                    'referenceCategory' => 'PACKAGE-MANAGER',
                    'referenceType' => 'purl',
                    'referenceLocator' => $package->purl,
                ]],
            ], fn ($value) => $value !== null);
            $relationships[] = [
                'spdxElementId' => 'SPDXRef-DOCUMENT',
                'relationshipType' => 'DESCRIBES',
                'relatedSpdxElement' => $spdxId,
            ];
        }

        return [
            'spdxVersion' => 'SPDX-2.3',
            'dataLicense' => 'CC0-1.0',
            'SPDXID' => 'SPDXRef-DOCUMENT',
            'name' => $image,
            'documentNamespace' => 'https://coolify.io/spdx/'.Str::uuid(),
            'creationInfo' => [
                'created' => now()->toIso8601ZuluString(),
                'creators' => ['Tool: Coolify'],
            ],
            'packages' => $packages,
            'relationships' => $relationships,
        ];
    }

    /**
     * @return list<Package>
     */
    private static function packages(ImageSbom $sbom): array
    {
        $packages = [];
        foreach ((array) $sbom->packages as $row) {
            if (is_array($row)) {
                $packages[] = Package::fromArray($row);
            }
        }

        return $packages;
    }

    /**
     * An SPDX expression only when every license is a plain identifier, else NOASSERTION.
     *
     * @param  list<string>  $licenses
     */
    private static function licenseExpression(array $licenses): string
    {
        if ($licenses === []) {
            return 'NOASSERTION';
        }

        foreach ($licenses as $license) {
            if (preg_match('/\A[A-Za-z0-9.+-]{1,64}\z/', $license) !== 1) {
                return 'NOASSERTION';
            }
        }

        return implode(' AND ', array_values(array_unique($licenses)));
    }
}
