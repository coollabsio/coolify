<?php

namespace App\Livewire\Project\Application;

use App\Actions\Docker\GetContainersStatus;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Previews extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public string $deployment_uuid;

    public array $parameters;

    public Collection $pull_requests;

    public int $rate_limit_remaining;

    public $domainConflicts = [];

    public $showDomainConflictModal = false;

    public $forceSaveDomains = false;

    public $pendingPreviewId = null;

    public array $previewFqdns = [];

    protected $rules = [
        'previewFqdns.*' => 'string|nullable',
    ];

    public function mount()
    {
        $this->pull_requests = collect();
        $this->parameters = get_route_parameters();
        $this->syncData(false);
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            foreach ($this->previewFqdns as $key => $fqdn) {
                $preview = $this->application->previews->get($key);
                if ($preview) {
                    $preview->fqdn = $fqdn;
                }
            }
        } else {
            $this->previewFqdns = [];
            foreach ($this->application->previews as $key => $preview) {
                $this->previewFqdns[$key] = $preview->fqdn;
            }
        }
    }

    public function load_prs()
    {
        try {
            $this->authorize('update', $this->application);
            ['rate_limit_remaining' => $rate_limit_remaining, 'data' => $data] = githubApi(source: $this->application->source, endpoint: "/repos/{$this->application->git_repository}/pulls");
            $this->rate_limit_remaining = $rate_limit_remaining;
            $this->pull_requests = $data->sortBy('number')->values();
        } catch (\Throwable $e) {
            $this->rate_limit_remaining = 0;

            return handleError($e, $this);
        }
    }

    public function confirmDomainUsage()
    {
        $this->forceSaveDomains = true;
        $this->showDomainConflictModal = false;
        if ($this->pendingPreviewId) {
            $this->save_preview($this->pendingPreviewId);
            $this->pendingPreviewId = null;
        }
    }

    public function save_preview($preview_id)
    {
        try {
            $this->authorize('update', $this->application);
            $success = true;
            $preview = $this->application->previews->find($preview_id);

            if (! $preview) {
                throw new \Exception('Preview not found');
            }

            // Find the key for this preview in the collection
            $previewKey = $this->application->previews->search(function ($item) use ($preview_id) {
                return $item->id == $preview_id;
            });

            if ($previewKey !== false && isset($this->previewFqdns[$previewKey])) {
                $fqdn = $this->previewFqdns[$previewKey];

                if (! empty($fqdn)) {
                    $fqdn = str($fqdn)->replaceEnd(',', '')->trim();
                    $fqdn = str($fqdn)->replaceStart(',', '')->trim();
                    $fqdn = str($fqdn)->trim()->lower();
                    $this->previewFqdns[$previewKey] = $fqdn;

                    if (! validateDNSEntry($fqdn, $this->application->destination->server)) {
                        $this->dispatch('error', 'Validating DNS failed.', "Make sure you have added the DNS records correctly.<br><br>$fqdn->{$this->application->destination->server->ip}<br><br>Check this <a target='_blank' class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/dns-configuration'>documentation</a> for further help.");
                        $success = false;
                    }

                    // Check for domain conflicts if not forcing save
                    if (! $this->forceSaveDomains) {
                        $result = checkDomainUsage(resource: $this->application, domain: $fqdn);
                        if ($result['hasConflicts']) {
                            $this->domainConflicts = $result['conflicts'];
                            $this->showDomainConflictModal = true;
                            $this->pendingPreviewId = $preview_id;

                            return;
                        }
                    } else {
                        // Reset the force flag after using it
                        $this->forceSaveDomains = false;
                    }
                }
            }

            if ($success) {
                $this->syncData(true);
                $preview->save();
                $this->dispatch('success', 'Preview saved.<br><br>Do not forget to redeploy the preview to apply the changes.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function generate_preview($preview_id)
    {
        try {
            $this->authorize('update', $this->application);

            $preview = $this->application->previews->find($preview_id);
            if (! $preview) {
                $this->dispatch('error', 'Preview not found.');

                return;
            }
            if ($this->application->build_pack === 'dockercompose') {
                $preview->generate_preview_fqdn_compose();
                $this->application->refresh();
                $this->syncData(false);
                $this->dispatch('success', 'Domain generated.');

                return;
            }

            $preview->generate_preview_fqdn();
            $this->application->refresh();
            $this->syncData(false);
            $this->dispatch('update_links');
            $this->dispatch('success', 'Domain generated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function add(int $pull_request_id, ?string $pull_request_html_url = null)
    {
        try {
            $this->authorize('update', $this->application);
            if ($this->application->build_pack === 'dockercompose') {
                $this->setDeploymentUuid();
                $found = ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', $pull_request_id)->first();
                if (! $found && ! is_null($pull_request_html_url)) {
                    $found = ApplicationPreview::create([
                        'application_id' => $this->application->id,
                        'pull_request_id' => $pull_request_id,
                        'pull_request_html_url' => $pull_request_html_url,
                        'docker_compose_domains' => $this->application->docker_compose_domains,
                    ]);
                }
                $found->generate_preview_fqdn_compose();
                $this->application->refresh();
                $this->syncData(false);
            } else {
                $this->setDeploymentUuid();
                $found = ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', $pull_request_id)->first();
                if (! $found && ! is_null($pull_request_html_url)) {
                    $found = ApplicationPreview::create([
                        'application_id' => $this->application->id,
                        'pull_request_id' => $pull_request_id,
                        'pull_request_html_url' => $pull_request_html_url,
                    ]);
                }
                $found->generate_preview_fqdn();
                $this->application->refresh();
                $this->syncData(false);
                $this->dispatch('update_links');
                $this->dispatch('success', 'Preview added.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function force_deploy_without_cache(int $pull_request_id, ?string $pull_request_html_url = null)
    {
        $this->authorize('deploy', $this->application);

        $this->deploy($pull_request_id, $pull_request_html_url, force_rebuild: true);
    }

    public function add_and_deploy(int $pull_request_id, ?string $pull_request_html_url = null)
    {
        $this->authorize('deploy', $this->application);

        $this->add($pull_request_id, $pull_request_html_url);
        $this->deploy($pull_request_id, $pull_request_html_url);
    }

    public function deploy(int $pull_request_id, ?string $pull_request_html_url = null, bool $force_rebuild = false)
    {
        $this->authorize('deploy', $this->application);

        try {
            $this->setDeploymentUuid();
            $found = ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', $pull_request_id)->first();
            if (! $found && ! is_null($pull_request_html_url)) {
                ApplicationPreview::create([
                    'application_id' => $this->application->id,
                    'pull_request_id' => $pull_request_id,
                    'pull_request_html_url' => $pull_request_html_url,
                ]);
            }
            $result = queue_application_deployment(
                application: $this->application,
                deployment_uuid: $this->deployment_uuid,
                force_rebuild: $force_rebuild,
                pull_request_id: $pull_request_id,
                git_type: $found->git_type ?? null,
            );
            if ($result['status'] === 'skipped') {
                $this->dispatch('success', 'Deployment skipped', $result['message']);

                return;
            }

            return redirect()->route('project.application.deployment.show', [
                'project_uuid' => $this->parameters['project_uuid'],
                'application_uuid' => $this->parameters['application_uuid'],
                'deployment_uuid' => $this->deployment_uuid,
                'environment_uuid' => $this->parameters['environment_uuid'],
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    protected function setDeploymentUuid()
    {
        $this->deployment_uuid = new Cuid2;
        $this->parameters['deployment_uuid'] = $this->deployment_uuid;
    }

    private function stopContainers(array $containers, $server)
    {
        $containersToStop = collect($containers)->pluck('Names')->toArray();

        foreach ($containersToStop as $containerName) {
            instant_remote_process(command: [
                "docker stop --time=30 $containerName",
                "docker rm -f $containerName",
            ], server: $server, throwError: false);
        }
    }

    public function stop(int $pull_request_id)
    {
        $this->authorize('deploy', $this->application);

        try {
            $server = $this->application->destination->server;

            if ($this->application->destination->server->isSwarm()) {
                instant_remote_process(["docker stack rm {$this->application->uuid}-{$pull_request_id}"], $server);
            } else {
                $containers = getCurrentApplicationContainerStatus($server, $this->application->id, $pull_request_id)->toArray();
                $this->stopContainers($containers, $server);
            }

            GetContainersStatus::run($server);
            $this->application->refresh();
            $this->dispatch('containerStatusUpdated');
            $this->dispatch('success', 'Preview Deployment stopped.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete(int $pull_request_id)
    {
        try {
            $this->authorize('delete', $this->application);
            $preview = ApplicationPreview::where('application_id', $this->application->id)
                ->where('pull_request_id', $pull_request_id)
                ->first();

            if (! $preview) {
                $this->dispatch('error', 'Preview not found.');

                return;
            }

            // Soft delete immediately for instant UI feedback
            $preview->delete();

            // Dispatch the job for async cleanup (container stopping + force delete)
            DeleteResourceJob::dispatch($preview);

            // Refresh the application and its previews relationship to reflect the soft delete
            $this->application->load('previews');
            $this->dispatch('update_links');
            $this->dispatch('success', 'Preview deletion started. It may take a few moments to complete.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
