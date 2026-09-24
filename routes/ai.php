<?php

use App\Http\Middleware\ApiAllowed;
use App\Mcp\Servers\CoolifyServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', CoolifyServer::class)
    ->middleware(['mcp.enabled', 'auth:sanctum', 'api.token.team', ApiAllowed::class, 'mcp.team.enabled']);
