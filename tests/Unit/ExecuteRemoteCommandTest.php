<?php

use App\Traits\ExecuteRemoteCommand;
use Illuminate\Support\Collection;

require_once __DIR__.'/../../app/Traits/ExecuteRemoteCommand.php';

function remoteCommandOutputCollector(): object
{
    return new class
    {
        use ExecuteRemoteCommand;

        public Collection $saved_outputs;

        public function __construct()
        {
            $this->save = 'dockerfile';
            $this->saved_outputs = collect();
        }

        public function collectOutput(string $output, bool $append = true): void
        {
            $this->saveCommandOutput($output, $append);
        }

        public function trimmedOutput(string $key = 'dockerfile'): string
        {
            return $this->trimmedSavedOutput($key)->value();
        }
    };
}

it('preserves whitespace across streamed saved output chunks', function () {
    $collector = remoteCommandOutputCollector();

    foreach (["FROM alpine\nARG FIRST", "\n", 'ARG', ' ', "SECOND\nRUN true\n"] as $chunk) {
        $collector->collectOutput($chunk);
    }

    expect((string) $collector->saved_outputs->get('dockerfile'))
        ->toBe("FROM alpine\nARG FIRST\nARG SECOND\nRUN true\n");
});

it('trims saved output when append is disabled', function () {
    $collector = remoteCommandOutputCollector();
    $collector->collectOutput('old');

    $collector->collectOutput(" new output\n", append: false);

    expect((string) $collector->saved_outputs->get('dockerfile'))->toBe('new output');
});

it('keeps non-appended command output safe for exact status comparisons', function () {
    $collector = remoteCommandOutputCollector();

    $collector->collectOutput("\"healthy\"\n", append: false);

    expect(str($collector->saved_outputs->get('dockerfile'))->replace('"', '')->value())
        ->toBe('healthy');
});

it('normalizes streamed scalar output without changing the saved value', function () {
    $collector = remoteCommandOutputCollector();
    $collector->collectOutput("node\n");

    expect($collector->trimmedOutput())->toBe('node')
        ->and((string) $collector->saved_outputs->get('dockerfile'))->toBe("node\n");
});
