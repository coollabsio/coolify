<?php

namespace App\Listeners;

use Illuminate\Foundation\Events\MaintenanceModeDisabled as EventsMaintenanceModeDisabled;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class MaintenanceModeDisabledNotification
{
    public function __construct() {}

    public function handle(EventsMaintenanceModeDisabled $event): void
    {
        ray('Maintenance mode disabled!');
        $files = Storage::disk('webhooks-during-maintenance')->files();
        $files = collect($files);
        $files = $files->sort();
        foreach ($files as $file) {
            $content = Storage::disk('webhooks-during-maintenance')->get($file);
            $data = json_decode($content, true);
            $symfonyRequest = new SymfonyRequest(
                $data['query'],
                $data['request'],
                $data['attributes'],
                $data['cookies'],
                $data['files'],
                $data['server'],
                $data['content']
            );

            foreach ($data['headers'] as $key => $value) {
                $symfonyRequest->headers->set($key, $value);
            }
            $request = Request::createFromBase($symfonyRequest);
            $endpoint = str($file)->after('_')->beforeLast('_')->value();
            $class = "App\Http\Controllers\Webhook\\".ucfirst(str($endpoint)->before('::')->value());
            $method = str($endpoint)->after('::')->value();
            try {
                $instance = new $class;
                $instance->$method($request);
            } catch (\Throwable $th) {
                ray($th);
            } finally {
                Storage::disk('webhooks-during-maintenance')->delete($file);
            }
        }
    }
}
