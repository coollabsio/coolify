<?php

namespace App\Providers;

use App\Contracts\CustomJobRepositoryInterface;
use App\Repositories\CustomJobRepository;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Contracts\JobRepository;

class HorizonServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(JobRepository::class, CustomJobRepository::class);
        $this->app->singleton(CustomJobRepositoryInterface::class, CustomJobRepository::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
