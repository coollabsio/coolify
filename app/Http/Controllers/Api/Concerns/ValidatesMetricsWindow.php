<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Server;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * The time window of the metrics endpoints: `minutes` before now, or `from` to `to` (now when
 * not sent). The window cannot start before the Sentinel metrics retention of the server.
 */
trait ValidatesMetricsWindow
{
    /**
     * @param  array<string, mixed>  $rules  more query rules of the endpoint
     * @return array{0: int, 1: ?CarbonInterface} the window length in minutes and its end (null for now)
     *
     * @throws HttpResponseException with a 422 response when the query is invalid
     */
    private function metricsWindow(Request $request, Server $metricsServer, array $rules = []): array
    {
        $validator = customApiValidator($request->query(), [
            'minutes' => 'sometimes|integer|min:1|prohibits:from,to',
            'from' => 'sometimes|date|required_with:to',
            'to' => 'sometimes|date|after:from',
            ...$rules,
        ]);

        if ($validator->fails()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422));
        }

        $retentionDays = (int) $metricsServer->settings->sentinel_metrics_history_days;
        $retentionError = "The requested metrics history exceeds the configured Sentinel retention period of {$retentionDays} day(s).";

        if (! $request->query->has('from')) {
            $minutes = (int) $request->query('minutes', 10);
            if ($minutes > $retentionDays * 24 * 60) {
                $this->throwMetricsWindowError('minutes', $retentionError);
            }

            return [$minutes, null];
        }

        $from = CarbonImmutable::parse($request->query('from'))->utc();
        $to = $request->query->has('to') ? CarbonImmutable::parse($request->query('to'))->utc() : null;

        if ($from->lt(now()->subDays($retentionDays))) {
            $this->throwMetricsWindowError('from', $retentionError);
        }

        if ($to === null && $from->gte(now())) {
            $this->throwMetricsWindowError('from', 'The from field must be a date before now.');
        }

        // Rounded up to whole minutes, so the window can start up to a minute before `from`.
        $minutes = max(1, (int) ceil(abs($from->diffInSeconds($to ?? now())) / 60));

        return [$minutes, $to];
    }

    private function throwMetricsWindowError(string $field, string $message): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Validation failed.',
            'errors' => [$field => [$message]],
        ], 422));
    }
}
