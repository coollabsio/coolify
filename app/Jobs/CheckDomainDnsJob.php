<?php

namespace App\Jobs;

use App\Actions\Shared\CheckDomainDns;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\ServiceApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class CheckDomainDnsJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public Application|ApplicationPreview|ServiceApplication $resource,
        public string $statusKey,
        public string $url,
        public ?Server $server,
        public ?string $expectedIp,
        public string $checkId,
        public bool $skipForMultipleServers = false,
    ) {}

    public function handle(): void
    {
        $this->persistResults(CheckDomainDns::run(
            [$this->statusKey => $this->url],
            $this->server,
            $this->expectedIp,
            $this->skipForMultipleServers,
        ));
    }

    public function failed(?\Throwable $exception): void
    {
        $this->persistResults([
            $this->statusKey => $this->status('failed', 'Could not validate DNS for this domain.'),
        ]);
    }

    /**
     * @return array{status: string, message: string, expected_ip: ?string, checked_at: string}
     */
    private function status(string $status, string $message): array
    {
        return [
            'status' => $status,
            'message' => $message,
            'expected_ip' => $this->expectedIp,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, array{status: string, message: string, expected_ip: ?string, checked_at: string}>  $results
     */
    private function persistResults(array $results): void
    {
        DB::transaction(function () use ($results): void {
            $resource = $this->resource::query()->lockForUpdate()->find($this->resource->getKey());
            if (! $resource) {
                return;
            }

            $statuses = $resource->domain_dns_statuses ?? [];

            foreach ($results as $key => $result) {
                if (($statuses[$key]['status'] ?? null) !== 'checking' || ($statuses[$key]['check_id'] ?? null) !== $this->checkId) {
                    continue;
                }

                $statuses[$key] = $result;
            }

            $resource->domain_dns_statuses = $statuses === [] ? null : $statuses;
            $resource->save();
        });
    }
}
