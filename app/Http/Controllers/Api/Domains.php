<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class Domains extends Controller
{
    public function deleteDomains(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $validator = Validator::make($request->all(), [
            'uuid' => 'required|string|exists:applications,uuid',
            'domains' => 'required|array',
            'domains.*' => 'required|string|distinct',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $application = Application::where('uuid', $request->uuid)->first();

        if (! $application) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found',
            ], 404);
        }

        $existingDomains = explode(',', $application->fqdn);
        $domainsToDelete = $request->domains;
        $updatedDomains = array_diff($existingDomains, $domainsToDelete);
        $application->fqdn = implode(',', $updatedDomains);
        $application->custom_labels = base64_encode(implode("\n ", generateLabelsApplication($application)));
        $application->save();

        return response()->json([
            'success' => true,
            'message' => 'Domains updated successfully',
            'application' => $application,
        ]);
    }
}
