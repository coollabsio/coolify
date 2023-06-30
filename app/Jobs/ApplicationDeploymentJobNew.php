<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Url\Url;
use Throwable;
use Visus\Cuid2\Cuid2;

class ApplicationDeploymentJobNew implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public static int $batch_counter = 0;

    private int $application_deployment_queue_id;

    private ApplicationDeploymentQueue $application_deployment_queue;
    private Application $application;
    private string $deployment_uuid;
    private int $pull_request_id;
    private string $commit;
    private bool $force_rebuild;

    private GithubApp|GitlabApp $source;
    private StandaloneDocker|SwarmDocker $destination;
    private Server $server;
    private string $private_key_location;
    private ApplicationPreview|null $preview = null;

    private string $container_name;
    private string $workdir;
    private bool $is_debug_enabled;

    public function __construct(int $application_deployment_queue_id)
    {
        $this->application_deployment_queue = ApplicationDeploymentQueue::find($application_deployment_queue_id);
        $this->application = Application::find($this->application_deployment_queue->application_id);

        $this->application_deployment_queue_id = $application_deployment_queue_id;
        $this->deployment_uuid = $this->application_deployment_queue->deployment_uuid;
        $this->pull_request_id = $this->application_deployment_queue->pull_request_id;
        $this->commit = $this->application_deployment_queue->commit;
        $this->force_rebuild = $this->application_deployment_queue->force_rebuild;

        $this->source = $this->application->source->getMorphClass()::where('id', $this->application->source->id)->first();
        $this->destination = $this->application->destination->getMorphClass()::where('id', $this->application->destination->id)->first();
        $this->server = $this->destination->server;
        $this->private_key_location = save_private_key_for_server($this->server);

        $this->workdir = "/artifacts/{$this->deployment_uuid}";
        $this->is_debug_enabled = $this->application->settings->is_debug_enabled;

        $this->container_name = generate_container_name($this->application->uuid);
        $this->private_key_location = save_private_key_for_server($this->server);

        // Set preview fqdn
        if ($this->pull_request_id !== 0) {
            $this->preview = ApplicationPreview::findPreviewByApplicationAndPullId($this->application->id, $this->pull_request_id);
            if ($this->application->fqdn) {
                $preview_fqdn = data_get($this->preview, 'fqdn');
                $template = $this->application->preview_url_template;
                $url = Url::fromString($this->application->fqdn);
                $host = $url->getHost();
                $schema = $url->getScheme();
                $random = new Cuid2(7);
                $preview_fqdn = str_replace('{{random}}', $random, $template);
                $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
                $preview_fqdn = str_replace('{{pr_id}}', $this->pull_request_id, $preview_fqdn);
                $preview_fqdn = "$schema://$preview_fqdn";
                $this->preview->fqdn = $preview_fqdn;
                $this->preview->save();
            }
        }
    }

    public function handle(): void
    {
        $this->application_deployment_queue->update([
            'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        ]);
        try {
            if ($this->pull_request_id !== 0) {
                // $this->deploy_pull_request();
            } else {
                $this->deploy();
            }
        } catch (\Exception $e) {
            // $this->execute_now([
            //     "echo '\nOops something is not okay, are you okay? 😢'",
            //     "echo '\n\n{$e->getMessage()}'",
            // ]);
            $this->fail($e->getMessage());
        } finally {
            // if (isset($this->docker_compose)) {
            //     Storage::disk('deployments')->put(Str::kebab($this->application->name) . '/docker-compose.yml', $this->docker_compose);
            // }
            // execute_remote_command(
            //     commands: [
            //         "docker rm -f {$this->deployment_uuid} >/dev/null 2>&1"
            //     ],
            //     server: $this->server,
            //     queue: $this->application_deployment_queue,
            //     hide_from_output: true,
            // );
        }
    }
    public function failed(Throwable $exception): void
    {
        ray($exception);
        $this->next(ApplicationDeploymentStatus::FAILED->value);
    }
    private function execute_in_builder(string $command)
    {
        return "docker exec {$this->deployment_uuid} bash -c '{$command} |& tee -a /proc/1/fd/1'";
    }
    private function deploy()
    {
        execute_remote_command(
            commands: [
                "echo -n 'Pulling latest version of the builder image (ghcr.io/coollabsio/coolify-builder).'",
            ],
            server: $this->server,
            queue: $this->application_deployment_queue,
        );
        execute_remote_command(
            commands: [
                "docker run --pull=always -d --name {$this->deployment_uuid} --rm -v /var/run/docker.sock:/var/run/docker.sock ghcr.io/coollabsio/coolify-builder",
            ],
            server: $this->server,
            queue: $this->application_deployment_queue,
            show_in_output: false,
        );
        execute_remote_command(
            commands: [
                "echo 'Done.'",
            ],
            server: $this->server,
            queue: $this->application_deployment_queue,
        );
        execute_remote_command(
            commands: [
                $this->execute_in_builder("mkdir -p {$this->workdir}")
            ],
            server: $this->server,
            queue: $this->application_deployment_queue,
        );
        execute_remote_command(
            commands: [
                "echos hello"
            ],
            server: $this->server,
            queue: $this->application_deployment_queue,
        );
        $this->next(ApplicationDeploymentStatus::FINISHED->value);
    }

    private function next(string $status)
    {
        // If the deployment is cancelled by the user, don't update the status
        if ($this->application_deployment_queue->status !== ApplicationDeploymentStatus::CANCELLED_BY_USER->value) {
            $this->application_deployment_queue->update([
                'status' => $status,
            ]);
        }
        queue_next_deployment($this->application);
    }
}
