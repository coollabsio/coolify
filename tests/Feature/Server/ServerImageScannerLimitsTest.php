<?php

use App\Actions\ImageScan\ScanImage;
use App\Enums\ImageScanErrorCode;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
});

it('keeps the server scan lock for the Syft and Grype time limits of the server', function () {
    $default = ScanImage::lockTtl($this->server);
    expect(ScanImage::sbomStepTimeout($this->server))->toBe(1260);

    $this->server->settings->forceFill(['image_scan_syft_timeout' => 1800, 'image_scan_grype_timeout' => 1200])->saveQuietly();
    $this->server->load('settings');

    expect(ScanImage::sbomStepTimeout($this->server))->toBe(600 + 1800 + 60)
        ->and(ScanImage::lockTtl($this->server))->toBe($default + (1800 - 600) + 2 * (1200 - 600))
        ->and(ScanImage::gateScanReserveSeconds($this->server))->toBe(600 + 1800 + 60 + 1200 + 60)
        ->and(ScanImage::lockTtl($this->server))->toBeLessThanOrEqual(ScanImage::MAX_LOCK_TTL);
});

it('names the scanner and its setting when a scan step times out', function (string $step, string $message) {
    expect(ImageScanErrorCode::Timeout->message(['server' => 'main', 'step' => $step]))->toBe($message);
})->with([
    'syft' => ['sbom', 'Syft took too long and was stopped. Raise the Syft time limit on the Vulnerabilities page of main.'],
    'grype' => ['grype', 'Grype took too long and was stopped. Raise the Grype time limit on the Vulnerabilities page of main.'],
    'other step' => ['pull', 'The scan took too long and was stopped.'],
]);
