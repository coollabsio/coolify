<?php

declare(strict_types=1);

namespace Tests;

use Throwable;

arch()->preset()->php();
arch()->preset()->security();
arch()->preset()->strict();

arch('App')
    ->expect('App')
    ->not->toBeEnums()
    ->ignoring('App\Enums')
    ->not->toImplement(Throwable::class)
    ->ignoring('App\Exceptions')
    ->not->toExtend('Illuminate\Database\Eloquent\Model')
    ->ignoring('App\Models')
    ->not->toExtend('Illuminate\Foundation\Http\FormRequest')
    ->ignoring('App\Http\Requests')
    ->not->toExtend('Illuminate\Console\Command')
    ->ignoring('App\Console\Commands')
    ->not->toExtend('Illuminate\Mail\Mailable')
    ->ignoring('App\Mail')
    ->not->toExtend('Illuminate\Notifications\Notification')
    ->ignoring('App\Notifications')
    ->not->toExtend('Illuminate\Support\ServiceProvider')
    ->ignoring('App\Providers')
    ->not->toHaveSuffix('ServiceProvider')
    ->ignoring('App\Providers')
    ->not->toHaveSuffix('Controller')
    ->ignoring('App\Http\Controllers');

arch('Actions')
    ->expect('App\Actions')
    ->toBeClasses()
    ->toExtendNothing()
    ->toImplementNothing()
    ->not->toHavePublicMethodsBesides(['handle'])
    ->toHaveLineCountLessThan(250)
    ->not->toHaveSuffix('Action');

arch('Concerns')
    ->expect('App\Concerns')
    ->toBeTraits()
    ->toExtendNothing()
    ->toImplementNothing()
    ->toHaveLineCountLessThan(100)
    ->toHavePrefix('Has');

arch('Commands')
    ->expect('App\Console\Commands')
    ->toBeClasses()
    ->toExtend('Illuminate\Console\Command')
    ->toImplementNothing()
    ->not->toHavePublicMethodsBesides(['handle'])
    ->toHaveLineCountLessThan(150)
    ->toHaveSuffix('Command');

arch('Contracts')
    ->expect('App\Contracts')
    ->toBeInterfaces()
    ->toExtendNothing()
    ->toImplementNothing()
    ->toHaveLineCountLessThan(100);

arch('Enums')
    ->expect('App\Enums')
    ->toBeEnums()
    ->ignoring('App\Enums\Concerns')
    ->toExtendNothing()
    ->toImplementNothing()
    ->toHaveLineCountLessThan(80);

arch('Features')
    ->expect('App\Features')
    ->toBeClasses()
    ->ignoring('App\Features\Concerns')
    ->toHaveMethod('resolve')
    ->toHaveLineCountLessThan(250);

arch('Events')
    ->expect('App\Events')
    ->toBeClasses()
    ->toExtendNothing()
    ->toHaveLineCountLessThan(100)
    ->not->toHaveSuffix('Event');

arch('Exceptions')
    ->expect('App\Exceptions')
    ->toBeClasses()
    ->toImplement('Throwable')
    ->ignoring('App\Exceptions\Handler')
    ->toHaveLineCountLessThan(150)
    ->toHaveSuffix('Exception');

arch('Http')
    ->expect('App\Http')
    ->toBeClasses()
    ->toOnlyBeUsedIn('App\Http');

arch('Controllers')
    ->expect('App\Http\Controllers')
    ->toBeClasses()
    ->not->toHavePublicMethodsBesides(['__construct', '__invoke', 'index', 'show', 'create', 'store', 'edit', 'update', 'destroy', 'middleware'])
    ->toOnlyBeUsedIn('App\Http\Controllers')
    ->toHaveLineCountLessThan(250)
    ->ignoring('App\Http\Controllers\Api')
    ->toHaveSuffix('Controller');

arch('Middleware')
    ->expect('App\Http\Middleware')
    ->toBeClasses()
    ->not->toHavePublicMethodsBesides(['handle'])
    ->toHaveLineCountLessThan(150);

arch('Requests')
    ->expect('App\Http\Requests')
    ->toBeClasses()
    ->toExtend('Illuminate\Foundation\Http\FormRequest')
    ->toHaveMethod('rules')
    ->toOnlyBeUsedIn('App\Http\Controllers')
    ->toHaveLineCountLessThan(150)
    ->toHaveSuffix('Request');

arch('Jobs')
    ->expect('App\Jobs')
    ->toBeClasses()
    ->toImplement('Illuminate\Contracts\Queue\ShouldQueue')
    ->not->toHavePublicMethodsBesides(['handle'])
    ->toHaveLineCountLessThan(250)
    ->toHaveSuffix('Job');

arch('Listeners')
    ->expect('App\Listeners')
    ->toBeClasses()
    ->not->toHavePublicMethodsBesides(['__construct', 'handle'])
    ->toHaveLineCountLessThan(150);

arch('Mail')
    ->expect('App\Mail')
    ->toBeClasses()
    ->toExtend('Illuminate\Mail\Mailable')
    ->toImplement('Illuminate\Contracts\Queue\ShouldQueue')
    ->toHaveLineCountLessThan(150);

arch('Models')
    ->expect('App\Models')
    ->toBeClasses()
    ->toOnlyUse('Illuminate\Database')
    ->not->toUseTrait('Illuminate\Database\Eloquent\SoftDeletes')
    ->toHaveLineCountLessThan(250)
    ->not->toHaveSuffix('Model');

arch('Notifications')
    ->expect('App\Notifications')
    ->toBeClasses()
    ->toExtend('Illuminate\Notifications\Notification')
    ->toHaveLineCountLessThan(150)
    ->not->toHaveSuffix('Notification');

arch('Policies')
    ->expect('App\Policies')
    ->toBeClasses()
    ->toHaveLineCountLessThan(150)
    ->toHaveSuffix('Policy');

arch('Providers')
    ->expect('App\Providers')
    ->toBeClasses()
    ->toHaveSuffix('ServiceProvider')
    ->toExtend('Illuminate\Support\ServiceProvider')
    ->not->toBeUsed()
    ->toHaveLineCountLessThan(250);

arch('Queries')
    ->expect('App\Queries')
    ->toBeClasses()
    ->toExtend('Illuminate\Database\Eloquent\Builder')
    ->not->toHavePublicMethodsBesides(['__construct', 'builder'])
    ->toHaveLineCountLessThan(150);

arch('Services')
    ->expect('App\Services')
    ->toBeClasses()
    ->toHaveLineCountLessThan(250);

arch('Traits')
    ->expect('App\Traits')
    ->toBeTraits()
    ->toExtendNothing()
    ->toImplementNothing()
    ->toHaveLineCountLessThan(100);

arch('Functions')
    ->expect(['dd', 'ddd', 'dump', 'env', 'exit'])
    ->not->toBeUsed();
