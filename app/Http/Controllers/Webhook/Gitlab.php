<?php

namespace App\Http\Controllers\Webhook;

use App\Actions\Application\CleanupPreviewDeployment;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationPreview;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;

class Gitlab extends Controller
{
    public function manual(Request $request)
    {
        try {
            $return_payloads = collect([]);
            $payload = $request->collect();
            $headers = $request->headers->all();
            $x_gitlab_token = data_get($headers, 'x-gitlab-token.0');
            $x_gitlab_event = data_get($payload, 'object_kind');
            $allowed_events = ['push', 'merge_request'];
            if (! in_array($x_gitlab_event, $allowed_events)) {
                $return_payloads->push([
                    'status' => 'failed',
                    'message' => 'Event not allowed. Only push and merge_request events are allowed.',
                ]);

                return response($return_payloads);
            }

            if (empty($x_gitlab_token)) {
                $return_payloads->push([
                    'status' => 'failed',
                    'message' => 'Invalid signature.',
                ]);

                return response($return_payloads);
            }

            if ($x_gitlab_event === 'push') {
                $branch = data_get($payload, 'ref');
                $full_name = data_get($payload, 'project.path_with_namespace');
                if (Str::isMatch('/refs\/heads\/*/', $branch)) {
                    $branch = Str::after($branch, 'refs/heads/');
                }
                if (! $branch) {
                    $return_payloads->push([
                        'status' => 'failed',
                        'message' => 'Nothing to do. No branch found in the request.',
                    ]);

                    return response($return_payloads);
                }
                $added_files = data_get($payload, 'commits.*.added');
                $removed_files = data_get($payload, 'commits.*.removed');
                $modified_files = data_get($payload, 'commits.*.modified');
                $changed_files = collect($added_files)->concat($removed_files)->concat($modified_files)->unique()->flatten();
            }
            if ($x_gitlab_event === 'merge_request') {
                $action = data_get($payload, 'object_attributes.action');
                $branch = data_get($payload, 'object_attributes.source_branch');
                $base_branch = data_get($payload, 'object_attributes.target_branch');
                $full_name = data_get($payload, 'project.path_with_namespace');
                $pull_request_id = data_get($payload, 'object_attributes.iid');
                $pull_request_html_url = data_get($payload, 'object_attributes.url');
                if (! $branch) {
                    $return_payloads->push([
                        'status' => 'failed',
                        'message' => 'Nothing to do. No branch found in the request.',
                    ]);

                    return response($return_payloads);
                }
            }
            $applications = Application::where('git_repository', 'like', "%$full_name%");
            if ($x_gitlab_event === 'push') {
                $applications = $applications->where('git_branch', $branch)->get();
                if ($applications->isEmpty()) {
                    $return_payloads->push([
                        'status' => 'failed',
                        'message' => "Nothing to do. No applications found with deploy key set, branch is '$branch' and Git Repository name has $full_name.",
                    ]);

                    return response($return_payloads);
                }
            }
            if ($x_gitlab_event === 'merge_request') {
                $applications = $applications->where('git_branch', $base_branch)->get();
                if ($applications->isEmpty()) {
                    $return_payloads->push([
                        'status' => 'failed',
                        'message' => "Nothing to do. No applications found with branch '$base_branch'.",
                    ]);

                    return response($return_payloads);
                }
            }
            foreach ($applications as $application) {
                $webhook_secret = data_get($application, 'manual_webhook_secret_gitlab');
                if ($webhook_secret !== $x_gitlab_token) {
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
                        'message' => 'Server is not functional',
                    ]);

                    continue;
                }
                if ($x_gitlab_event === 'push') {
                    if ($application->isDeployable()) {
                        $is_watch_path_triggered = $application->isWatchPathsTriggered($changed_files);
                        if ($is_watch_path_triggered || is_null($application->watch_paths)) {
                            $deployment_uuid = new Cuid2;
                            $result = queue_application_deployment(
                                application: $application,
                                deployment_uuid: $deployment_uuid,
                                commit: data_get($payload, 'after', 'HEAD'),
                                force_rebuild: false,
                                is_webhook: true,
                            );
                            if ($result['status'] === 'queue_full') {
                                return response($result['message'], 429)->header('Retry-After', 60);
                            } elseif ($result['status'] === 'skipped') {
                                $return_payloads->push([
                                    'status' => $result['status'],
                                    'message' => $result['message'],
                                    'application_uuid' => $application->uuid,
                                    'application_name' => $application->name,
                                ]);
                            } else {
                                $return_payloads->push([
                                    'status' => 'success',
                                    'message' => 'Deployment queued.',
                                    'application_uuid' => $application->uuid,
                                    'application_name' => $application->name,
                                ]);
                            }
                        } else {
                            $paths = str($application->watch_paths)->explode("\n");
                            $return_payloads->push([
                                'status' => 'failed',
                                'message' => 'Changed files do not match watch paths. Ignoring deployment.',
                                'application_uuid' => $application->uuid,
                                'application_name' => $application->name,
                                'details' => [
                                    'changed_files' => $changed_files,
                                    'watch_paths' => $paths,
                                ],
                            ]);
                        }
                    } else {
                        $return_payloads->push([
                            'status' => 'failed',
                            'message' => 'Deployments disabled',
                            'application_uuid' => $application->uuid,
                            'application_name' => $application->name,
                        ]);
                    }
                }
                if ($x_gitlab_event === 'merge_request') {
                    if ($action === 'open' || $action === 'opened' || $action === 'synchronize' || $action === 'reopened' || $action === 'reopen' || $action === 'update') {
                        if ($application->isPRDeployable()) {
                            $deployment_uuid = new Cuid2;
                            $found = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pull_request_id)->first();
                            if (! $found) {
                                if ($application->build_pack === 'dockercompose') {
                                    $pr_app = ApplicationPreview::create([
                                        'git_type' => 'gitlab',
                                        'application_id' => $application->id,
                                        'pull_request_id' => $pull_request_id,
                                        'pull_request_html_url' => $pull_request_html_url,
                                        'docker_compose_domains' => $application->docker_compose_domains,
                                    ]);
                                    $pr_app->generate_preview_fqdn_compose();
                                } else {
                                    $pr_app = ApplicationPreview::create([
                                        'git_type' => 'gitlab',
                                        'application_id' => $application->id,
                                        'pull_request_id' => $pull_request_id,
                                        'pull_request_html_url' => $pull_request_html_url,
                                    ]);
                                    $pr_app->generate_preview_fqdn();
                                }
                            }
                            $result = queue_application_deployment(
                                application: $application,
                                pull_request_id: $pull_request_id,
                                deployment_uuid: $deployment_uuid,
                                commit: data_get($payload, 'object_attributes.last_commit.id', 'HEAD'),
                                force_rebuild: false,
                                is_webhook: true,
                                git_type: 'gitlab'
                            );
                            if ($result['status'] === 'queue_full') {
                                return response($result['message'], 429)->header('Retry-After', 60);
                            } elseif ($result['status'] === 'skipped') {
                                $return_payloads->push([
                                    'application' => $application->name,
                                    'status' => 'skipped',
                                    'message' => $result['message'],
                                ]);
                            } else {
                                $return_payloads->push([
                                    'application' => $application->name,
                                    'status' => 'success',
                                    'message' => 'Preview Deployment queued',
                                ]);
                            }
                        } else {
                            $return_payloads->push([
                                'application' => $application->name,
                                'status' => 'failed',
                                'message' => 'Preview deployments disabled',
                            ]);
                        }
                    } elseif ($action === 'closed' || $action === 'close' || $action === 'merge') {
                        $found = ApplicationPreview::where('application_id', $application->id)->where('pull_request_id', $pull_request_id)->first();
                        if ($found) {
                            // Use comprehensive cleanup that cancels active deployments,
                            // kills helper containers, and removes all PR containers
                            CleanupPreviewDeployment::run($application, $pull_request_id, $found);

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
                    } else {
                        $return_payloads->push([
                            'application' => $application->name,
                            'status' => 'failed',
                            'message' => 'No action found. Contact us for debugging.',
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
