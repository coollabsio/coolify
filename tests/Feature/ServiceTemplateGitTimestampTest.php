<?php

use App\Console\Commands\Generate\Services;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

it('adds the last git commit timestamp to generated service template payloads', function () {
    $command = new Services;
    $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
    $method = new ReflectionMethod($command, 'processFile');

    $payload = $method->invoke($command, 'activepieces.yaml');

    $expectedTimestamp = Process::run([
        'git',
        'log',
        '-1',
        '--format=%cI',
        '--',
        'templates/compose/activepieces.yaml',
    ])->throw()->output();

    expect($payload)
        ->toHaveKey('template_last_updated_at')
        ->and($payload['template_last_updated_at'])
        ->toBe(trim($expectedTimestamp));
});
