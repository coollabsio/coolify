<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use function Termwind\ask;
use function Termwind\render;
use function Termwind\style;

class NotifyDemo extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:demo-notify {channel?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a demo notification, to a given channel. Run to see options.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $channel = $this->argument('channel');

        if (blank($channel)) {
            $this->showHelp();

            return;
        }

        ray($channel);
    }

    private function showHelp()
    {
        style('coolify')->color('#9333EA');
        style('title-box')->apply('mt-1 px-2 py-1 bg-coolify');

        render(
            <<<'HTML'
        <div>
            <div class="title-box">
                Coolify
            </div>
            <p class="mt-1 ml-1 ">
              Demo Notify <strong class="text-coolify">=></strong> Send a demo notification to a given channel.
            </p>
            <p class="px-1 mt-1 ml-1 bg-coolify">
              php artisan app:demo-notify {channel}
            </p>
            <div class="my-1">
                <div class="text-yellow-500"> Channels: </div>
                <ul class="text-coolify">
                    <li>email</li>
                    <li>slack</li>
                    <li>discord</li>
                    <li>telegram</li>
                </ul>
            </div>
        </div>
        HTML
        );

        ask(<<<'HTML'
        <div class="mr-1">
            In which manner you wish a <strong class="text-coolify">coolified</strong> notification?
        </div>
        HTML, ['email', 'slack', 'discord', 'telegram']);
    }
}
