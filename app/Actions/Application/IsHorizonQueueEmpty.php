<?php

namespace App\Actions\Application;

use Laravel\Horizon\Contracts\JobRepository;
use Lorisleiva\Actions\Concerns\AsAction;

class IsHorizonQueueEmpty
{
    use AsAction;

    public function handle()
    {
        $hostname = gethostname();
        $recent = app(JobRepository::class)->getRecent();
        if ($recent) {
            $running = $recent->filter(function ($job) use ($hostname) {
                $payload = json_decode($job->payload);
                $tags = data_get($payload, 'tags');

                return $job->status != 'completed' &&
                       $job->status != 'failed' &&
                       isset($tags) &&
                       is_array($tags) &&
                       in_array('server:'.$hostname, $tags);
            });
            if ($running->count() > 0) {
                echo 'false';

                return false;
            }
        }
        echo 'true';

        return true;
    }
}
