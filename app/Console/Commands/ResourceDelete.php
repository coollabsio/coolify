<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Service;
use App\Models\StandalonePostgresql;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

class ResourceDelete extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resource:delete';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a resource from the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $resource = select(
            'What resource do you want to delete?',
            ['Application', 'Database', 'Service'],
        );
        if ($resource === 'Application') {
            $this->deleteApplication();
        } elseif ($resource === 'Database') {
            $this->deleteDatabase();
        } elseif ($resource === 'Service') {
            $this->deleteService();
        }
    }
    private function deleteApplication()
    {
        $applications = Application::all();
        $application = select(
            'What application do you want to delete?',
            $applications->pluck('name')->toArray(),
        );
        $application = $applications->where('name', $application)->first();
        $confirmed = confirm("Are you sure you want to delete {$application->name}?");
        if (!$confirmed) {
            return;
        }
        $application->delete();
    }
    private function deleteDatabase()
    {
        $databases = StandalonePostgresql::all();
        $database = select(
            'What database do you want to delete?',
            $databases->pluck('name')->toArray(),
        );
        $database = $databases->where('name', $database)->first();
        $confirmed = confirm("Are you sure you want to delete {$database->name}?");
        if (!$confirmed) {
            return;
        }
        $database->delete();
    }
    private function deleteService()
    {
        $services = Service::all();
        $service = select(
            'What service do you want to delete?',
            $services->pluck('name')->toArray(),
        );
        $service = $services->where('name', $service)->first();
        $confirmed = confirm("Are you sure you want to delete {$service->name}?");
        if (!$confirmed) {
            return;
        }
        $service->delete();
    }
}
