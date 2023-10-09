<?php

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;

class Cloud extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cloud:unused-servers';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get Unused Servers from Cloud';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Server::all()->whereNotNull('team.subscription')->where('team.subscription.stripe_trial_already_ended',true)->each(function($server){
            $this->info($server->name);
        });
    }
}
