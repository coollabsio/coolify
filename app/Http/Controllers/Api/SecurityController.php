<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PrivateKey;
use Illuminate\Http\Request;

class SecurityController extends Controller
{
    public function keys(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $keys = PrivateKey::where('team_id', $teamId)->get();

        return response()->json([
            'success' => true,
            'data' => serializeApiResponse($keys),
        ]);
    }

    public function key_by_uuid(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $key = PrivateKey::where('team_id', $teamId)->where('uuid', $request->uuid)->first();

        if (is_null($key)) {
            return response()->json([
                'success' => false,
                'message' => 'Key not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => serializeApiResponse($key),
        ]);
    }

    public function create_key(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }
        $validator = customApiValidator($request->all(), [
            'name' => 'string|max:255',
            'description' => 'string|max:255',
            'private_key' => 'required|string',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();

            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }
        if (! $request->name) {
            $request->offsetSet('name', generate_random_name());
        }
        if (! $request->description) {
            $request->offsetSet('description', 'Created by Coolify via API');
        }
        $key = PrivateKey::create([
            'team_id' => $teamId,
            'name' => $request->name,
            'description' => $request->description,
            'private_key' => $request->private_key,
        ]);

        return response()->json([
            'success' => true,
            'data' => serializeApiResponse($key),
        ]);
    }

    public function update_key(Request $request)
    {
        $allowedFields = ['name', 'description', 'private_key'];
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        $validator = customApiValidator($request->all(), [
            'name' => 'string|max:255',
            'description' => 'string|max:255',
            'private_key' => 'required|string',
        ]);

        $extraFields = array_diff(array_keys($request->all()), $allowedFields);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }
        $foundKey = PrivateKey::where('team_id', $teamId)->where('uuid', $request->uuid)->first();
        if (is_null($foundKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Key not found.',
            ], 404);
        }
        $foundKey->update($request->all());

        return response()->json([
            'success' => true,
            'data' => serializeApiResponse($foundKey),
        ])->setStatusCode(201);
    }

    public function delete_key(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        if (! $request->uuid) {
            return response()->json(['success' => false, 'message' => 'UUID is required.'], 422);
        }

        $key = PrivateKey::where('team_id', $teamId)->where('uuid', $request->uuid)->first();
        if (is_null($key)) {
            return response()->json(['success' => false, 'message' => 'Key not found.'], 404);
        }
        $key->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Key deleted.',
        ]);
    }
}
