<?php

namespace App\Http\Livewire\Project\New;

use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Service;
use Livewire\Component;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class DockerCompose extends Component
{
    public string $dockerComposeRaw = '';
    public string $envFile = '';
    public array $parameters;
    public array $query;
    public function mount()
    {

        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        if (isDev()) {
            $this->dockerComposeRaw = 'services:
            ghost:
              image: ghost:5
              volumes:
                - ~/configs:/etc/configs/:ro
                - ./var/lib/ghost/content:/tmp/ghost2/content:ro
                - /var/lib/ghost/content:/tmp/ghost/content:rw
                - ghost-content-data:/var/lib/ghost/content
                - type: volume
                  source: mydata
                  target: /data
                  volume:
                    nocopy: true
                - type: bind
                  source: ./var/lib/ghost/data
                  target: /data
                - type: bind
                  source: /tmp
                  target: /tmp
              labels:
                - "test.label=true"
              ports:
                - "3000"
                - "3000-3005"
                - "8000:8000"
                - "9090-9091:8080-8081"
                - "49100:22"
                - "127.0.0.1:8001:8001"
                - "127.0.0.1:5000-5010:5000-5010"
                - "127.0.0.1::5000"
                - "6060:6060/udp"
                - "12400-12500:1240"
                - target: 80
                  published: 8080
                  protocol: tcp
                  mode: host
              networks:
                - some-network
                - other-network
              environment:
                - database__client=${DATABASE_CLIENT:-mysql}
                - database__connection__database=${MYSQL_DATABASE:-ghost}
                - database__connection__host=${DATABASE_CONNECTION_HOST:-mysql}
                - test=${TEST:?true}
                - url=$SERVICE_FQDN_GHOST
                - database__connection__user=$SERVICE_USER_MYSQL
                - database__connection__password=$SERVICE_PASSWORD_MYSQL
              depends_on:
                - mysql
            mysql:
              image: mysql:8.0
              volumes:
                - ghost-mysql-data:/var/lib/mysql
              environment:
                - MYSQL_USER=${SERVICE_USER_MYSQL}
                - MYSQL_PASSWORD=${SERVICE_PASSWORD_MYSQL}
                - MYSQL_DATABASE=$MYSQL_DATABASE
                - MYSQL_ROOT_PASSWORD=${SERVICE_PASSWORD_MYSQLROOT}
                - SESSION_SECRET
            minio:
              image: minio/minio
              environment:
                RACK_ENV: development
                A: $A
                SHOW: ${SHOW}
                SHOW1: ${SHOW2-show1}
                SHOW2: ${SHOW3:-show2}
                SHOW3: ${SHOW4?show3}
                SHOW4: ${SHOW5:?show4}
                SHOW5: ${SERVICE_USER_MINIO}
                SHOW6: ${SERVICE_PASSWORD_MINIO}
                SHOW7: ${SERVICE_PASSWORD_64_MINIO}
                SHOW8: ${SERVICE_BASE64_64_MINIO}
                SHOW9: ${SERVICE_BASE64_128_MINIO}
                SHOW10: ${SERVICE_BASE64_MINIO}
                SHOW11:
          ';
        }
    }
    public function submit()
    {
        try {
            $this->validate([
                'dockerComposeRaw' => 'required'
            ]);
            $this->dockerComposeRaw = Yaml::dump(Yaml::parse($this->dockerComposeRaw), 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            $server_id = $this->query['server_id'];

            $project = Project::where('uuid', $this->parameters['project_uuid'])->first();
            $environment = $project->load(['environments'])->environments->where('name', $this->parameters['environment_name'])->first();
            $service = Service::create([
                'name' => 'service' . Str::random(10),
                'docker_compose_raw' => $this->dockerComposeRaw,
                'environment_id' => $environment->id,
                'server_id' => (int) $server_id,
            ]);
            $variables = parseEnvFormatToArray($this->envFile);
            foreach ($variables as $key => $variable) {
                EnvironmentVariable::create([
                    'key' => $key,
                    'value' => $variable,
                    'is_build_time' => false,
                    'is_preview' => false,
                    'service_id' => $service->id,
                ]);
            }
            $service->name = "service-$service->uuid";

            $service->parse(isNew: true);

            return redirect()->route('project.service', [
                'service_uuid' => $service->uuid,
                'environment_name' => $environment->name,
                'project_uuid' => $project->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
