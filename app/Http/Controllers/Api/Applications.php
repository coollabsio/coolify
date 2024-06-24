<?php

namespace App\Http\Controllers\Api;

use App\Actions\Application\StopApplication;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Project;
use Illuminate\Http\Request;
use Visus\Cuid2\Cuid2;

class Applications extends Controller
{
    public function applications(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $projects = Project::where('team_id', $teamId)->get();
        $applications = collect();
        $applications->push($projects->pluck('applications')->flatten());
        $applications = $applications->flatten();

        return response()->json($applications);
    }

    public function application_by_uuid(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['error' => 'UUID is required.'], 400);
        }
        $application = Application::where('uuid', $uuid)->first();
        if (! $application) {
            return response()->json(['error' => 'Application not found.'], 404);
        }

        return response()->json($application);
    }

    public function update_by_uuid(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }

        if ($request->collect()->count() == 0) {
            return response()->json([
                'message' => 'No data provided.',
            ], 400);
        }
        $application = Application::where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
            ], 404);
        }
        ray($request->collect());

        // if ($request->has('domains')) {
        //     $existingDomains = explode(',', $application->fqdn);
        //     $newDomains = $request->domains;
        //     $filteredNewDomains = array_filter($newDomains, function ($domain) use ($existingDomains) {
        //         return ! in_array($domain, $existingDomains);
        //     });
        //     $mergedDomains = array_unique(array_merge($existingDomains, $filteredNewDomains));
        //     $application->fqdn = implode(',', $mergedDomains);
        //     $application->custom_labels = base64_encode(implode("\n ", generateLabelsApplication($application)));
        //     $application->save();
        // }

        return response()->json([
            'message' => 'Application updated successfully.',
            'application' => serialize_api_response($application),
        ]);
    }

    public function action_deploy(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $force = $request->query->get('force') ?? false;
        $instant_deploy = $request->query->get('instant_deploy') ?? false;
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['error' => 'UUID is required.'], 400);
        }
        $application = Application::where('uuid', $uuid)->first();
        if (! $application) {
            return response()->json(['error' => 'Application not found.'], 404);
        }

        $deployment_uuid = new Cuid2(7);

        queue_application_deployment(
            application: $application,
            deployment_uuid: $deployment_uuid,
            force_rebuild: $force,
            is_api: true,
            no_questions_asked: $instant_deploy
        );

        return response()->json(
            [
                'message' => 'Deployment request queued.',
                'deployment_uuid' => $deployment_uuid->toString(),
                'deployment_api_url' => base_url().'/api/v1/deployment/'.$deployment_uuid->toString(),
            ],
            200
        );
    }

    public function action_stop(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $uuid = $request->route('uuid');
        $sync = $request->query->get('sync') ?? false;
        if (! $uuid) {
            return response()->json(['error' => 'UUID is required.'], 400);
        }
        $application = Application::where('uuid', $uuid)->first();
        if (! $application) {
            return response()->json(['error' => 'Application not found.'], 404);
        }
        if ($sync) {
            StopApplication::run($application);

            return response()->json(['message' => 'Stopped the application.'], 200);
        } else {
            StopApplication::dispatch($application);

            return response()->json(['message' => 'Stopping request queued.'], 200);
        }
    }

    public function action_restart(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $uuid = $request->route('uuid');
        if (! $uuid) {
            return response()->json(['error' => 'UUID is required.'], 400);
        }
        $application = Application::where('uuid', $uuid)->first();
        if (! $application) {
            return response()->json(['error' => 'Application not found.'], 404);
        }

        $deployment_uuid = new Cuid2(7);

        queue_application_deployment(
            application: $application,
            deployment_uuid: $deployment_uuid,
            restart_only: true,
            is_api: true,
        );

        return response()->json(
            [
                'message' => 'Restart request queued.',
                'deployment_uuid' => $deployment_uuid->toString(),
                'deployment_api_url' => base_url().'/api/v1/deployment/'.$deployment_uuid->toString(),
            ],
            200
        );

    }
}
