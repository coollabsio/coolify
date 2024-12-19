<?php

namespace App\Livewire\Project\Application;

use App\Actions\Docker\GetContainersStatus;
use App\Models\Application;
use App\Models\ApplicationPreview;
use Carbon\Carbon;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Livewire\Component;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

class Previews extends Component
{
    public Application $application;

    public string $deployment_uuid;

    public array $parameters;

    public Collection $pull_requests;

    public int $rate_limit_remaining;

    protected $rules = [
        'application.previews.*.fqdn' => 'string|nullable',
    ];

    public function mount()
    {
        $this->pull_requests = collect();
        $this->parameters = get_route_parameters();
    }

    public function load_prs()
    {
        try {
            ['rate_limit_remaining' => $rate_limit_remaining, 'data' => $data] = githubApi(source: $this->application->source, endpoint: "/repos/{$this->application->git_repository}/pulls");
            $this->rate_limit_remaining = $rate_limit_remaining;
            $this->pull_requests = $data->sortBy('number')->values();
        } catch (\Throwable $e) {
            $this->rate_limit_remaining = 0;

            return handleError($e, $this);
        }
    }

    public function save_preview($preview_id)
    {
        try {
            $success = true;
            $preview = $this->application->previews->find($preview_id);
            if (data_get_str($preview, 'fqdn')->isNotEmpty()) {
                $preview->fqdn = str($preview->fqdn)->replaceEnd(',', '')->trim();
                $preview->fqdn = str($preview->fqdn)->replaceStart(',', '')->trim();
                $preview->fqdn = str($preview->fqdn)->trim()->lower();
                if (! validate_dns_entry($preview->fqdn, $this->application->destination->server)) {
                    $this->dispatch('error', 'Validating DNS failed.', "Make sure you have added the DNS records correctly.<br><br>$preview->fqdn->{$this->application->destination->server->ip}<br><br>Check this <a target='_blank' class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/dns-configuration'>documentation</a> for further help.");
                    $success = false;
                }
                check_domain_usage(resource: $this->application, domain: $preview->fqdn);
            }

            if (! $preview) {
                throw new \Exception('Preview not found');
            }
            $success && $preview->save();
            $success && $this->dispatch('success', 'Preview saved.<br><br>Do not forget to redeploy the preview to apply the changes.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function generate_preview($preview_id)
    {
        $preview = $this->application->previews->find($preview_id);
        if (! $preview) {
            $this->dispatch('error', 'Preview not found.');

            return;
        }
        if ($this->application->build_pack === 'dockercompose') {
            $preview->generate_preview_fqdn_compose();
            $this->application->refresh();
            $this->dispatch('success', 'Domain generated.');

            return;
        }

        $fqdn = generateFqdn($this->application->destination->server, $this->application->uuid);
        $url = Url::fromString($fqdn);
        $template = $this->application->preview_url_template;
        $host = $url->getHost();
        $schema = $url->getScheme();
        $random = new Cuid2;
        $preview_fqdn = str_replace('{{random}}', $random, $template);
        $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
        $preview_fqdn = str_replace('{{pr_id}}', $preview->pull_request_id, $preview_fqdn);
        $preview_fqdn = "$schema://$preview_fqdn";
        $preview->fqdn = $preview_fqdn;
        $preview->save();
        $this->dispatch('success', 'Domain generated.');
    }

    public function add(int $pull_request_id, ?string $pull_request_html_url = null)
    {
        try {
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
                $this->application->generate_preview_fqdn($pull_request_id);
                $this->application->refresh();
                $this->dispatch('update_links');
                $this->dispatch('success', 'Preview added.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function add_and_deploy(int $pull_request_id, ?string $pull_request_html_url = null)
    {
        $this->add($pull_request_id, $pull_request_html_url);
        $this->deploy($pull_request_id, $pull_request_html_url);
    }

    public function deploy(int $pull_request_id, ?string $pull_request_html_url = null)
    {
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
            queue_application_deployment(
                application: $this->application,
                deployment_uuid: $this->deployment_uuid,
                force_rebuild: false,
                pull_request_id: $pull_request_id,
                git_type: $found->git_type ?? null,
            );

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

    public function stop(int $pull_request_id)
    {
        try {
            $server = $this->application->destination->server;
            $timeout = 300;

            if ($this->application->destination->server->isSwarm()) {
                instant_remote_process(["docker stack rm {$this->application->uuid}-{$pull_request_id}"], $server);
            } else {
                $containers = getCurrentApplicationContainerStatus($server, $this->application->id, $pull_request_id)->toArray();
                $this->stopContainers($containers, $server, $timeout);
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
            $server = $this->application->destination->server;
            $timeout = 300;

            if ($this->application->destination->server->isSwarm()) {
                instant_remote_process(["docker stack rm {$this->application->uuid}-{$pull_request_id}"], $server);
            } else {
                $containers = getCurrentApplicationContainerStatus($server, $this->application->id, $pull_request_id)->toArray();
                $this->stopContainers($containers, $server, $timeout);
            }

            ApplicationPreview::where('application_id', $this->application->id)
                ->where('pull_request_id', $pull_request_id)
                ->first()
                ->delete();

            $this->application->refresh();
            $this->dispatch('update_links');
            $this->dispatch('success', 'Preview deleted.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function stopContainers(array $containers, $server, int $timeout)
    {
        $processes = [];
        foreach ($containers as $container) {
            $containerName = str_replace('/', '', $container['Names']);
            $processes[$containerName] = $this->stopContainer($containerName, $timeout);
        }

        $startTime = Carbon::now()->getTimestamp();
        while (count($processes) > 0) {
            $finishedProcesses = array_filter($processes, function ($process) {
                return ! $process->running();
            });
            foreach (array_keys($finishedProcesses) as $containerName) {
                unset($processes[$containerName]);
                $this->removeContainer($containerName, $server);
            }

            if (Carbon::now()->getTimestamp() - $startTime >= $timeout) {
                $this->forceStopRemainingContainers(array_keys($processes), $server);
                break;
            }

            usleep(100000);
        }
    }

    private function stopContainer(string $containerName, int $timeout): InvokedProcess
    {
        return Process::timeout($timeout)->start("docker stop --time=$timeout $containerName");
    }

    private function removeContainer(string $containerName, $server)
    {
        instant_remote_process(["docker rm -f $containerName"], $server, throwError: false);
    }

    private function forceStopRemainingContainers(array $containerNames, $server)
    {
        foreach ($containerNames as $containerName) {
            instant_remote_process(["docker kill $containerName"], $server, throwError: false);
            $this->removeContainer($containerName, $server);
        }
    }
}
