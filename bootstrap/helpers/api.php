<?php

function get_team_id_from_token()
{
    $token = auth()->user()->currentAccessToken();

    return data_get($token, 'team_id');
}
function invalid_token()
{
    return response()->json(['error' => 'Invalid token.', 'docs' => 'https://coolify.io/docs/api-reference/authorization'], 400);
}
