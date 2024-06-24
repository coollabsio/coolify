<?php

use Illuminate\Database\Eloquent\Collection;

function get_team_id_from_token()
{
    $token = auth()->user()->currentAccessToken();

    return data_get($token, 'team_id');
}
function invalid_token()
{
    return response()->json(['error' => 'Invalid token.', 'docs' => 'https://coolify.io/docs/api-reference/authorization'], 400);
}

function serialize_api_response($data)
{
    if (! $data instanceof Collection) {
        $data = collect($data);
    }
    $data = $data->sortKeys();
    $created_at = data_get($data, 'created_at');
    $updated_at = data_get($data, 'updated_at');
    if ($created_at) {
        unset($data['created_at']);
        $data['created_at'] = $created_at;

    }
    if ($updated_at) {
        unset($data['updated_at']);
        $data['updated_at'] = $updated_at;
    }
    if (data_get($data, 'id')) {
        $data = $data->prepend($data['id'], 'id');
    }

    return $data;
}
