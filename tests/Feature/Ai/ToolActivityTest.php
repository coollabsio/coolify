<?php

use App\Ai\Support\ToolActivity;

test('maps common tool verbs to a present-tense status label', function (string $tool, string $expected) {
    expect(ToolActivity::label($tool))->toBe($expected);
})->with([
    ['list_servers', 'Reading servers'],
    ['get_infrastructure_overview', 'Reading infrastructure overview'],
    ['search_docs', 'Searching docs'],
    ['read_doc_page', 'Reading doc page'],
    ['run_server_command', 'Running server command'],
    ['delete_resource', 'Deleting resource'],
    ['upsert_environment_variable', 'Updating environment variable'],
    ['deploy_application', 'Deploying application'],
]);

test('humanizes an unknown tool name with a running verb', function () {
    expect(ToolActivity::label('frobnicate_widget'))->toBe('Running frobnicate widget');
});

test('handles empty or null tool names gracefully', function () {
    expect(ToolActivity::label(''))->toBe('Working')
        ->and(ToolActivity::label(null))->toBe('Working');
});
