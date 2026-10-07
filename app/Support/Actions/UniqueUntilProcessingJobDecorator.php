<?php

namespace App\Support\Actions;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Lorisleiva\Actions\Decorators\UniqueJobDecorator;

/**
 * Queued action job whose unique lock is released when a worker starts it, not when it finishes.
 *
 * laravel-actions only provides a decorator that holds the lock until the job finishes. An action
 * opts in by returning this decorator from makeUniqueJob().
 */
class UniqueUntilProcessingJobDecorator extends UniqueJobDecorator implements ShouldBeUniqueUntilProcessing {}
