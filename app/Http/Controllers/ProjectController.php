<?php

namespace App\Http\Controllers;

use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    public function all()
    {
        return view('projects', [
            'projects' => Project::ownedByCurrentTeam()->get(),
            'servers' => Server::ownedByCurrentTeam()->count(),
        ]);
    }

    public function edit()
    {
        $projectUuid = request()->route('project_uuid');
        $teamId = currentTeam()->id;
        $project = Project::where('team_id', $teamId)->where('uuid', $projectUuid)->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        return view('project.edit', ['project' => $project]);
    }

    public function show()
    {
        $projectUuid = request()->route('project_uuid');
        $teamId = currentTeam()->id;

        $project = Project::where('team_id', $teamId)->where('uuid', $projectUuid)->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        $project->load(['environments']);
        return view('project.show', ['project' => $project]);
    }

    public function new()
    {
        $services = Cache::get('services', []);
        $type = Str::of(request()->query('type'));
        $destination_uuid = request()->query('destination');
        $server_id = request()->query('server');

        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first();
        if (!$environment) {
            return redirect()->route('dashboard');
        }
        if (in_array($type, DATABASE_TYPES)) {
            $standalone_postgresql = create_standalone_postgresql($environment->id, $destination_uuid);
            return redirect()->route('project.database.configuration', [
                'project_uuid' => $project->uuid,
                'environment_name' => $environment->name,
                'database_uuid' => $standalone_postgresql->uuid,
            ]);
        }
        if ($type->startsWith('one-click-service-')) {
            $oneClickServiceName = $type->after('one-click-service-')->value();
            $oneClickService = data_get($services, "$oneClickServiceName.compose");
            $oneClickDotEnvs = data_get($services, "$oneClickServiceName.envs", null);
            $oneClickConfiguration = data_get($services, "$oneClickServiceName.configuration.proxy", []);
            $oneClickConfiguration = collect($oneClickConfiguration);
            if ($oneClickDotEnvs) {
                $oneClickDotEnvs = Str::of(base64_decode($oneClickDotEnvs))->split('/\r\n|\r|\n/');
            }
            if ($oneClickService) {
                $service = Service::create([
                    'name' => "$oneClickServiceName-" . Str::random(10),
                    'docker_compose_raw' => base64_decode($oneClickService),
                    'environment_id' => $environment->id,
                    'server_id' => (int) $server_id,
                ]);
                $service->name = "$oneClickServiceName-" . $service->uuid;
                $service->save();
                if ($oneClickDotEnvs && $oneClickDotEnvs->count() > 0) {
                    $oneClickDotEnvs->each(function ($value) use ($service) {
                        $key = Str::before($value, '=');
                        $value = Str::of(Str::after($value, '='));
                        if ($value->contains('SERVICE_USER')) {
                            $value = Str::of(Str::random(10));
                        }
                        if ($value->contains('SERVICE_PASSWORD')) {
                            $value = Str::of(Str::password(symbols: false));
                        }
                        if ($value->contains('SERVICE_PASSWORD64')) {
                            $value = Str::of(Str::password(length: 64, symbols: false));
                        }
                        if ($value->contains('SERVICE_BASE64')) {
                            $length = Str::of($value)->after('SERVICE_BASE64_')->beforeLast('_')->value();
                            if (is_numeric($length)) {
                                $length = (int) $length;
                            } else {
                                $length = 1;
                            }
                            $value = Str::of(base64_encode(Str::password(length: $length, symbols: false)));
                        }
                        EnvironmentVariable::create([
                            'key' => $key,
                            'value' => $value->value(),
                            'service_id' => $service->id,
                            'is_build_time' => false,
                            'is_preview' => false,
                        ]);
                    });
                }
                $service->parse(isNew: true, configuration: $oneClickConfiguration);

                return redirect()->route('project.service', [
                    'service_uuid' => $service->uuid,
                    'environment_name' => $environment->name,
                    'project_uuid' => $project->uuid,
                ]);
            }
        }
        return view('project.new', [
            'type' => $type->value()
        ]);
    }

    public function resources()
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (!$project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('name', request()->route('environment_name'))->first();
        if (!$environment) {
            return redirect()->route('dashboard');
        }
        return view('project.resources', [
            'project' => $project,
            'environment' => $environment
        ]);
    }
}
