<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class UpdateChannel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:update-channel {channel : stable, preview or nightly}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Switch the update channel of the instance, as the update page does';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $channel = $this->argument('channel');
        if (! in_array($channel, ['stable', 'preview', 'nightly'], true)) {
            $this->error("{$channel} is not an update channel, expected stable, preview or nightly");

            return self::FAILURE;
        }

        $settings = instanceSettings();
        $settings->switchUpdateChannel($channel);
        $settings->save();
        invalidate_versions_cache();
        $this->info("The instance follows the {$channel} channel");

        return self::SUCCESS;
    }
}
