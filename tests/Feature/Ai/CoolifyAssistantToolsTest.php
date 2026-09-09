<?php

use App\Ai\Agents\CoolifyAssistant;
use App\Mcp\Tools\CancelDeployment;
use App\Mcp\Tools\Control;
use App\Mcp\Tools\Deploy;
use App\Mcp\Tools\GetCurrentTeam;
use App\Mcp\Tools\ListServers;

test('the assistant exposes read tools and excludes the mutating MCP tools', function () {
    $tools = (new CoolifyAssistant)->tools();
    $classes = array_map(fn ($t) => $t::class, [...$tools]);

    expect($classes)->toContain(ListServers::class)
        ->toContain(GetCurrentTeam::class)
        ->not->toContain(Control::class)
        ->not->toContain(Deploy::class)
        ->not->toContain(CancelDeployment::class);
});

test('the assistant has non-empty instructions', function () {
    expect((string) (new CoolifyAssistant)->instructions())->not->toBeEmpty();
});
