<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Service;
use App\Models\StandalonePostgresql;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

class ResourcesDelete extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resources:delete';

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
        if ($applications->count() === 0) {
            $this->error('There are no applications to delete.');
            return;
        }
        $applicationsToDelete = multiselect(
            'What application do you want to delete?',
            $applications->pluck('name')->toArray(),
        );
        $confirmed = confirm("Are you sure you want to delete all selected resources?");
        if (!$confirmed) {
            return;
        }
        foreach ($applicationsToDelete as $application) {
            $toDelete = $applications->where('name', $application)->first();
            $toDelete->delete();
        }
    }
    private function deleteDatabase()
    {
        $databases = StandalonePostgresql::all();
        if ($databases->count() === 0) {
            $this->error('There are no databases to delete.');
            return;
        }
        $databasesToDelete = multiselect(
            'What database do you want to delete?',
            $databases->pluck('name')->toArray(),
        );
        $confirmed = confirm("Are you sure you want to delete all selected resources?");
        if (!$confirmed) {
            return;
        }
        foreach ($databasesToDelete as $database) {
            $toDelete = $databases->where('name', $database)->first();
            $toDelete->delete();
        }

    }
    private function deleteService()
    {
        $services = Service::all();
        if ($services->count() === 0) {
            $this->error('There are no services to delete.');
            return;
        }
        $servicesToDelete = multiselect(
            'What service do you want to delete?',
            $services->pluck('name')->toArray(),
        );
        $confirmed = confirm("Are you sure you want to delete all selected resources?");
        if (!$confirmed) {
            return;
        }
        foreach ($servicesToDelete as $service) {
            $toDelete = $services->where('name', $service)->first();
            $toDelete->delete();
        }
    }
}
