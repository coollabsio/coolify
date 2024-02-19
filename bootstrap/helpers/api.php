<?php

function get_team_id_from_token()
{
    $token = auth()->user()->currentAccessToken();
    return data_get($token, 'team_id');
}
