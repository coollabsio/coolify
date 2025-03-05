<?php

namespace App\Http\Controllers;

use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ServerController extends Controller
{
    private function getServer(string $server_uuid)
    {
        $server = Server::ownedByCurrentTeam()->where('uuid', $server_uuid)->first();
        if (! $server) {
            abort(404);
        }

        return $server;
    }

    public function servers()
    {
        $servers = Server::ownedByCurrentTeam()->orderBy('created_at')->get();
        $servers = $servers->map(function ($server) {
            return [
                'name' => $server->name,
                'description' => $server->description,
                'uuid' => $server->uuid,
            ];
        });

        return Inertia::render('Servers/Index', [
            'servers' => $servers,
        ]);
    }

    public function server(string $server_uuid)
    {
        try {
            $server = $this->getServer($server_uuid);

            $server->settings = $server->settings->only(['wildcard_domain', 'server_timezone']);
            $server = $server->only(['id', 'uuid', 'name', 'description', 'settings']);

            return Inertia::render('Servers/General', [
                'server' => $server,
            ]);
        } catch (NotFoundHttpException $e) {
            return redirect()->route('next_servers');
        }
    }

    public function server_store(string $server_uuid, Request $request)
    {
        try {
            $server = $this->getServer($server_uuid);
            $server->update($request->only(['name', 'description']));
            $server->settings->update($request->only(['wildcard_domain', 'server_timezone']));

            return goto_route('next_server', $server_uuid);
        } catch (NotFoundHttpException $e) {
            return redirect()->route('next_servers');
        }
    }

    public function server_connection(string $server_uuid)
    {
        try {
            $server = $this->getServer($server_uuid);
            $server->privateKey = $server->privateKey->only(['id', 'uuid', 'name']);
            $privateKeys = PrivateKey::ownedByCurrentTeam()->where('id', '!=', data_get($server, 'privateKey.id'))->where('is_git_related', false)->get();
            $server = $server->only(['id', 'uuid', 'ip', 'user', 'port', 'name', 'description', 'privateKey']);

            $privateKeys = $privateKeys->map(function ($privateKey) {
                return [
                    'id' => $privateKey->id,
                    'uuid' => $privateKey->uuid,
                    'name' => $privateKey->name,
                ];
            });

            return Inertia::render('Servers/Connection', [
                'server' => $server,
                'private_keys' => $privateKeys,
            ]);
        } catch (NotFoundHttpException $e) {
            return redirect()->route('next_servers');
        }
    }

    public function server_connection_store(string $server_uuid, Request $request)
    {
        try {
            $server = $this->getServer($server_uuid);
        } catch (NotFoundHttpException $e) {
            return redirect()->route('next_servers');
        }

        try {
            $privateKeyId = $request->input('private_key_id');
            $originalIp = $server->ip;
            $originalUser = $server->user;
            $originalPort = $server->port;
            $originalPrivateKeyId = $server->private_key_id;
            PrivateKey::ownedByCurrentTeam()->where('id', $privateKeyId)->firstOrFail();

            $server->update($request->only(['ip', 'user', 'port', 'name', 'description']));
            $server->update(['private_key_id' => $privateKeyId]);
            ['uptime' => $uptime, 'error' => $error] = $server->validateConnection();
            if ($uptime) {
                return goto_route('next_server_connection', $server_uuid);
            } else {
                $server->update(['ip' => $originalIp, 'user' => $originalUser, 'port' => $originalPort, 'private_key_id' => $originalPrivateKeyId]);

                return goto_route('next_server_connection', $server_uuid)->withErrors(['error' => $error, 'original_private_key_id' => $originalPrivateKeyId, 'original_ip' => $originalIp, 'original_user' => $originalUser, 'original_port' => $originalPort]);
            }
        } catch (\Exception $e) {
            return goto_route('next_server_connection', $server_uuid)->withErrors(['error' => $e->getMessage(), 'original_private_key_id' => $originalPrivateKeyId, 'original_ip' => $originalIp, 'original_user' => $originalUser, 'original_port' => $originalPort]);
        }
    }

    public function server_connection_test(string $server_uuid)
    {
        try {
            $server = $this->getServer($server_uuid);
            ['uptime' => $uptime, 'error' => $error] = $server->validateConnection();
            if ($uptime) {
                return response()->json(['success' => true]);
            } else {
                return response()->json(['success' => false, 'error' => $error]);
            }
        } catch (NotFoundHttpException $e) {
            return response()->json(['success' => false]);
        } catch (\Exception $e) {
            return response()->json(['success' => false]);
        }
    }

    public function server_automations(string $server_uuid)
    {
        try {
            $server = $this->getServer($server_uuid);
            $server->settings = $server->settings->only(['docker_cleanup_frequency', 'docker_cleanup_threshold', 'force_docker_cleanup', 'delete_unused_volumes', 'delete_unused_networks', 'server_disk_usage_notification_threshold', 'server_disk_usage_check_frequency']);
            $server = $server->only(['id', 'uuid', 'name', 'description', 'settings']);

            return Inertia::render('Servers/Automations', [
                'server' => $server,
            ]);
        } catch (NotFoundHttpException $e) {
            return redirect()->route('next_servers');
        }
    }

    public function server_automations_store(string $server_uuid, Request $request)
    {
        try {
            $server = $this->getServer($server_uuid);
            $server->settings->update($request->only(['docker_cleanup_frequency', 'docker_cleanup_threshold', 'force_docker_cleanup', 'delete_unused_volumes', 'delete_unused_networks', 'server_disk_usage_notification_threshold', 'server_disk_usage_check_frequency']));

            return goto_route('next_server_automations', $server_uuid);
        } catch (NotFoundHttpException $e) {
            return redirect()->route('next_server_automations', $server_uuid);
        } catch (\Exception $e) {
            return redirect()->route('next_server_automations', $server_uuid)->withErrors(['error' => $e->getMessage()]);
        }
    }
}
