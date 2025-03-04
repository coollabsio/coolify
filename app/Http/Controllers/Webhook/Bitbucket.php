<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Livewire\Project\Service\Storage;
use App\Models\Application;
use App\Models\ApplicationPreview;
use Exception;
use Illuminate\Http\Request;
use Visus\Cuid2\Cuid2;

class Bitbucket extends Controller
{
    public function manual(Request $request)
    {
        try {
            if (app()->isDownForMaintenance()) {
                $epoch = now()->valueOf();
                $data = [
                    'attributes' => $request->attributes->all(),
                    'request' => $request->request->all(),
                    'query' => $request->query->all(),
                    'server' => $request->server->all(),
                    'files' => $request->files->all(),
                    'cookies' => $request->cookies->all(),
                    'headers' => $request->headers->all(),
                    'content' => $request->getContent(),
                ];
                $json = json_encode($data);
                Storage::disk('webhooks-during-maintenance')->put("{$epoch}_Bitbicket::manual_bitbucket", $json);

                return;
            }
            $return_payloads = collect([]);
            $payload = $request->collect();
            $headers = $request->headers->all();
            $x_bitbucket_token = data_get($headers, 'x-hub-signature.0', '');
            $x_bitbucket_event = data_get($headers, 'x-event-key.0', '');
            $handled_events = collect(['repo:push', 'pullrequest:updated', 'pullrequest:created', 'pullrequest:rejected', 'pullrequest:fulfilled']);
            if (! $handled_events->contains($x_bitbucket_event)) {
                return response([
                    'status' => 'failed',
                    'message' => 'Nothing to do. Event not handled.',
                ]);
            }
            if ($x_bitbucket_event === 'repo:push') {
                $branch = data_get($payload, 'push.changes.0.new.name');
                $full_name = data_get($payload, 'repository.full_name');
                $commit = data_get($payload, 'push.changes.0.new.target.hash');

                if (! $branch) {
                    return response([
                        'status' => 'failed',
                        'message' => 'Nothing to do. No branch found in the request.',
                    ]);
                }
            }
            if ($x_bitbucket_event === 'pullrequest:updated' || $x_bitbucket_event === 'pullrequest:created' || $x_bitbucket_event === 'pullrequest:rejected' || $x_bitbucket_event === 'pullrequest:fulfilled') {
                $branch = data_get($payload, 'pullrequest.destination.branch.name');
                $base_branch = data_get($payload, 'pullrequest.source.branch.name');
                $full_name = data_get($payload, 'repository.full_name');
                $pull_request_id = data_get($payload, 'pullrequest.id');
                $pull_request_html_url = data_get($payload, 'pullrequest.links.html.href');
                $commit = data_get($payload, 'pullrequest.source.commit.hash');
            }
            $applications = Application::where('git_repository', 'like', "%$full_name%");
            $applications = $applications->where('git_branch', $branch)->get();
            if ($applications->isEmpty()) {
                return response([
                    'status' => 'failed',
                    'message' => "Nothing to do. No applications found with deploy key set, branch is '$branch' and Git Repository name has $full_name.",
                ]);
            }
            foreach ($applications as $application) {
                $webhook_secret = data_get($application, 'manual_webhook_secret_bitbucket');
                $payload = $request->getContent();

                [$algo, $hash] = explode('=', $x_bitbucket_token, 2);
                $payloadHash = hash_hmac($algo, $payload, $webhook_secret);
                if (! hash_equals($hash, $payloadHash) && ! isDev()) {
                    $return_payloads->push([
                        'application' => $application->name,
                        'status' => 'failed',
                        'message' => 'Invalid signature.',
                    ]);

                    continue;
                }
                $isFunctional = $application->destination->server->isFunctional();
                if (! $isFunctional) {
                    $return_payloads->push([
                        'application' => $application->name,
                        'status' => 'failed',
                        'message' => 'Server is not functional.',
                    ]);

                    continue;
                }
                if ($x_bitbucket_event === 'repo:push') {
                    if ($application->isDeployable()) {
                        $deployment_uuid = new Cuid2;
                        queue_application_deployment(
                            application: $application,
                            deployment_uuid: $deployment_uuid,
                            commit: $commit,
                            force_rebuild: false,
                            is_webhook: true
                        );
                        $return_payloads->push([
                            'application' => $application->name,
                            'status' => 'success',
                            'message' => 'Preview deployment queued.',
                        ]);
                    } else {
                        $return_payloads->push([
                            'application' => $application->name,
                            'status' => 'failed',
                            'message' => 'Auto deployment disabled.',
                        ]);
                    }
                }
                if ($x_bitbucket_event === 'pullrequest:created' || $x_bitbucket_event === 'pullrequest:updated') {
                    if ($application->isPRDeployable()) {
                        $deployment_uuid = new Cuid2;
                        $found = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pull_request_id)->first();
                        if (! $found) {
                            if ($application->build_pack === 'dockercompose') {
                                $pr_app = ApplicationPreview::create([
                                    'git_type' => 'bitbucket',
                                    'application_id' => $application->id,
                                    'pull_request_id' => $pull_request_id,
                                    'pull_request_html_url' => $pull_request_html_url,
                                    'docker_compose_domains' => $application->docker_compose_domains,
                                ]);
                                $pr_app->generate_preview_fqdn_compose();
                            } else {
                                ApplicationPreview::create([
                                    'git_type' => 'bitbucket',
                                    'application_id' => $application->id,
                                    'pull_request_id' => $pull_request_id,
                                    'pull_request_html_url' => $pull_request_html_url,
                                ]);
                            }
                        }
                        queue_application_deployment(
                            application: $application,
                            pull_request_id: $pull_request_id,
                            deployment_uuid: $deployment_uuid,
                            force_rebuild: false,
                            commit: $commit,
                            is_webhook: true,
                            git_type: 'bitbucket'
                        );
                        $return_payloads->push([
                            'application' => $application->name,
                            'status' => 'success',
                            'message' => 'Preview deployment queued.',
                        ]);
                    } else {
                        $return_payloads->push([
                            'application' => $application->name,
                            'status' => 'failed',
                            'message' => 'Preview deployments disabled.',
                        ]);
                    }
                }
                if ($x_bitbucket_event === 'pullrequest:rejected' || $x_bitbucket_event === 'pullrequest:fulfilled') {
                    $found = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pull_request_id)->first();
                    if ($found) {
                        $found->delete();
                        $container_name = generateApplicationContainerName($application, $pull_request_id);
                        instant_remote_process(["docker rm -f $container_name"], $application->destination->server);
                        $return_payloads->push([
                            'application' => $application->name,
                            'status' => 'success',
                            'message' => 'Preview deployment closed.',
                        ]);
                    } else {
                        $return_payloads->push([
                            'application' => $application->name,
                            'status' => 'failed',
                            'message' => 'No preview deployment found.',
                        ]);
                    }
                }
            }

            return response($return_payloads);
        } catch (Exception $e) {
            return handleError($e);
        }
    }
}
