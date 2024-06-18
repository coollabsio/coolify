<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project as ModelsProject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class Domains extends Controller
{
    public function domains(Request $request)
    {
        $teamId = get_team_id_from_token();
        if (is_null($teamId)) {
            return invalid_token();
        }
        $uuid = $request->query->get('uuid');
        if ($uuid) {
            $domains = Application::getDomainsByUuid($uuid);

            return response()->json([
                'uuid' => $uuid,
                'domains' => $domains,
            ]);
        }
        $projects = ModelsProject::where('team_id', $teamId)->get();
        $domains = collect();
        $applications = $projects->pluck('applications')->flatten();
        $settings = InstanceSettings::get();
        if ($applications->count() > 0) {
            foreach ($applications as $application) {
                $ip = $application->destination->server->ip;
                $fqdn = str($application->fqdn)->explode(',')->map(function ($fqdn) {
                    return str($fqdn)->replace('http://', '')->replace('https://', '')->replace('/', '');
                });
                if ($ip === 'host.docker.internal') {
                    if ($settings->public_ipv4) {
                        $domains->push([
                            'domain' => $fqdn,
                            'ip' => $settings->public_ipv4,
                        ]);
                    }
                    if ($settings->public_ipv6) {
                        $domains->push([
                            'domain' => $fqdn,
                            'ip' => $settings->public_ipv6,
                        ]);
                    }
                    if (!$settings->public_ipv4 && !$settings->public_ipv6) {
                        $domains->push([
                            'domain' => $fqdn,
                            'ip' => $ip,
                        ]);
                    }
                } else {
                    $domains->push([
                        'domain' => $fqdn,
                        'ip' => $ip,
                    ]);
                }
            }
        }
        $services = $projects->pluck('services')->flatten();
        if ($services->count() > 0) {
            foreach ($services as $service) {
                $service_applications = $service->applications;
                if ($service_applications->count() > 0) {
                    foreach ($service_applications as $application) {
                        $fqdn = str($application->fqdn)->explode(',')->map(function ($fqdn) {
                            return str($fqdn)->replace('http://', '')->replace('https://', '')->replace('/', '');
                        });
                        if ($ip === 'host.docker.internal') {
                            if ($settings->public_ipv4) {
                                $domains->push([
                                    'domain' => $fqdn,
                                    'ip' => $settings->public_ipv4,
                                ]);
                            }
                            if ($settings->public_ipv6) {
                                $domains->push([
                                    'domain' => $fqdn,
                                    'ip' => $settings->public_ipv6,
                                ]);
                            }
                            if (!$settings->public_ipv4 && !$settings->public_ipv6) {
                                $domains->push([
                                    'domain' => $fqdn,
                                    'ip' => $ip,
                                ]);
                            }
                        } else {
                            $domains->push([
                                'domain' => $fqdn,
                                'ip' => $ip,
                            ]);
                        }
                    }
                }
            }
        }
        $domains = $domains->groupBy('ip')->map(function ($domain) {
            return $domain->pluck('domain')->flatten();
        })->map(function ($domain, $ip) {
            return [
                'ip' => $ip,
                'domains' => $domain,
            ];
        })->values();

        return response()->json($domains);
    }

    public function updateDomains(Request $request)
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
                'errors' => $validator->errors()
            ], 422);
        }

        $application = Application::where('uuid', $request->uuid)->first();

        if (!$application) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found'
            ], 404);
        }

        $existingDomains = explode(',', $application->fqdn);
        $newDomains = $request->domains;
        $filteredNewDomains = array_filter($newDomains, function ($domain) use ($existingDomains) {
            return !in_array($domain, $existingDomains);
        });
        $mergedDomains = array_unique(array_merge($existingDomains, $filteredNewDomains));
        $application->fqdn = implode(',', $mergedDomains);
        $application->custom_labels = base64_encode(implode("\n ", generateLabelsApplication($application)));
        $application->save();
        return response()->json([
            'success' => true,
            'message' => 'Domains updated successfully',
            'application' => $application
        ]);
    }

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
                'errors' => $validator->errors()
            ], 422);
        }

        $application = Application::where('uuid', $request->uuid)->first();

        if (!$application) {
            return response()->json([
                'success' => false,
                'message' => 'Application not found'
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
            'application' => $application
        ]);
    }
}
