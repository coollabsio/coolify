<?php

namespace App\Contracts;

use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository;

interface CustomJobRepositoryInterface extends JobRepository
{
    /**
     * Get all jobs with a specific status.
     */
    public function getJobsByStatus(string $status): Collection;

    /**
     * Get the count of jobs with a specific status.
     */
    public function countJobsByStatus(string $status): int;
}
