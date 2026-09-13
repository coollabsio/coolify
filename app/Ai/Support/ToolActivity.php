<?php

namespace App\Ai\Support;

use Illuminate\Support\Str;

/**
 * Turns a raw tool name (e.g. "list_servers", "search_docs") into a short,
 * present-tense status line the user sees while the assistant is working, so a
 * long tool-running pause reads as "Reading servers…" instead of silent dots.
 */
class ToolActivity
{
    /**
     * Leading verb in the tool name => the label verb shown to the user.
     *
     * @var array<string, string>
     */
    private const VERBS = [
        'list' => 'Reading',
        'get' => 'Reading',
        'read' => 'Reading',
        'show' => 'Reading',
        'fetch' => 'Reading',
        'describe' => 'Reading',
        'inspect' => 'Inspecting',
        'search' => 'Searching',
        'find' => 'Searching',
        'run' => 'Running',
        'execute' => 'Running',
        'control' => 'Updating',
        'deploy' => 'Deploying',
        'restart' => 'Restarting',
        'start' => 'Starting',
        'stop' => 'Stopping',
        'delete' => 'Deleting',
        'remove' => 'Deleting',
        'create' => 'Creating',
        'upsert' => 'Updating',
        'update' => 'Updating',
        'set' => 'Updating',
    ];

    public static function label(?string $toolName): string
    {
        $words = Str::of((string) $toolName)->snake()->replace('_', ' ')->squish();

        if ($words->isEmpty()) {
            return 'Working';
        }

        $parts = $words->explode(' ');
        $first = strtolower((string) $parts->first());

        if (isset(self::VERBS[$first])) {
            $rest = $parts->slice(1)->implode(' ');

            return trim(self::VERBS[$first].' '.$rest);
        }

        return 'Running '.$words;
    }
}
