<?php

use App\Models\Application;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| Shared helpers for managed DNS tests: an in-memory fake of the Cloudflare DNS record API.
*/

/**
 * Fakes the Cloudflare DNS record API with an in-memory record store.
 *
 * @param  array<int, array<string, mixed>>  $records
 */
function fakeCloudflareDns(array $records = []): ArrayObject
{
    $state = new ArrayObject(['records' => collect($records)->keyBy('id')->all(), 'next' => 1]);

    Http::fake(function (Request $request) use ($state) {
        if (! preg_match('#^https://api\.cloudflare\.com/client/v4/zones/[^/]+/dns_records(?:/([^/?]+))?(?:\?(.*))?$#', $request->url(), $matches)) {
            return Http::response(['success' => false], 404);
        }
        $id = ($matches[1] ?? '') !== '' ? $matches[1] : null;
        $records = $state['records'];

        if ($id === null && $request->method() === 'GET') {
            parse_str($matches[2] ?? '', $query);
            $result = collect($records)->filter(fn (array $record): bool => $record['name'] === ($query['name'] ?? null)
                && $record['type'] === ($query['type'] ?? null))->values()->all();

            return Http::response(['success' => true, 'result' => $result]);
        }
        if ($id === null && $request->method() === 'POST') {
            $id = 'record-new-'.$state['next'];
            $state['next']++;
            $records[$id] = array_merge(['proxied' => false, 'ttl' => 1, 'comment' => null], $request->data(), ['id' => $id]);
            $state['records'] = $records;

            return Http::response(['success' => true, 'result' => $records[$id]]);
        }
        if (! isset($records[$id])) {
            return Http::response(['success' => false, 'errors' => [['code' => 81044, 'message' => 'Record does not exist.']]], 404);
        }
        if ($request->method() === 'PATCH') {
            $records[$id] = array_merge($records[$id], $request->data());
            $state['records'] = $records;
        }
        if ($request->method() === 'DELETE') {
            unset($records[$id]);
            $state['records'] = $records;

            return Http::response(['success' => true, 'result' => ['id' => $id]]);
        }

        return Http::response(['success' => true, 'result' => $records[$id]]);
    });

    return $state;
}

function createDnsTestApplication(object $test, string $fqdn): Application
{
    $application = Application::factory()->create([
        'environment_id' => $test->environment->id,
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
        'fqdn' => $fqdn,
        'build_pack' => 'nixpacks',
    ]);
    $application->settings()->update(['is_container_label_readonly_enabled' => true]);

    return $application->fresh();
}

function sentDnsRequests(string $method): int
{
    return Http::recorded(fn (Request $request) => $request->method() === $method
        && str_contains($request->url(), 'api.cloudflare.com'))->count();
}
