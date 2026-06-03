<?php

use App\Mcp\Servers\CoolifyServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', CoolifyServer::class)
    ->middleware(['mcp.enabled', 'auth:sanctum', 'api.token.team']);
