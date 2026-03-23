<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\ContextualAttribute;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\ServiceProvider;
use Throwable;

arch()->preset()->php();
arch()->preset()->security();
arch('App')
    ->expect('App')
    ->toUseStrictTypes()
    ->toUseStrictEquality()
    ->not->toBeEnums()
    ->ignoring('App\Enums')
    ->classes()->not->toBeAbstract()
    ->classes()->toBeFinal()
    ->not->toExtend(Model::class)
    ->ignoring('App\Models')
    ->not->toExtend(FormRequest::class)
    ->ignoring('App\Http\Requests')
    ->not->toExtend(JsonResource::class)
    ->ignoring('App\Http\Resources')
    ->not->toExtend(ResourceCollection::class)
    ->ignoring('App\Http\Resources')
    ->not->toExtend(Command::class)
    ->ignoring('App\Console\Commands')
    ->not->toExtend(Mailable::class)
    ->ignoring('App\Mail')
    ->not->toExtend(Notification::class)
    ->ignoring('App\Notifications')
    ->not->toExtend(ServiceProvider::class)
    ->ignoring('App\Providers')
    ->not->toImplement(Throwable::class)
    ->ignoring('App\Exceptions')
    ->not->toImplement(ShouldQueue::class)
    ->ignoring('App\Jobs')
    ->not->toUseTrait(Dispatchable::class)
    ->ignoring('App\Jobs')
    ->not->toUseTrait(SerializesModels::class)
    ->ignoring('App\Events')
    ->not->toHaveSuffix('Controller')
    ->ignoring('App\Http\Controllers')
    ->not->toHaveSuffix('ServiceProvider')
    ->ignoring('App\Providers');

arch('Contracts')
    ->expect('App\Contracts')
    ->toBeInterfaces()
    ->toExtendNothing()
    ->toImplementNothing()
    ->toHaveLineCountLessThan(100);

arch('Attributes')
    ->expect('App\Attributes')
    ->toBeClasses()
    ->toHaveMethod(method: 'resolve')
    ->toHaveAttribute('Attribute')
    ->toImplement(ContextualAttribute::class)
    ->toHaveLineCountLessThan(100);

arch('Concerns')
    ->expect('App\Concerns')
    ->toBeTraits()
    ->toExtendNothing()
    ->toImplementNothing()
    ->toHaveLineCountLessThan(100)
    ->toHavePrefix('Has');

arch('Traits')
    ->expect('App\Traits')
    ->toBeTraits()
    ->toExtendNothing()
    ->toImplementNothing()
    ->toHaveLineCountLessThan(100)
    ->toHavePrefix('Has');

arch('Enums')
    ->expect('App\Enums')
    ->toBeEnums()
    ->ignoring('App\Enums\Concerns')
    ->toExtendNothing()
    ->toHaveLineCountLessThan(80);

arch('Exceptions')
    ->expect('App\Exceptions')
    ->toBeClasses()
    ->toImplement(Throwable::class)
    ->ignoring('App\Exceptions\Handler')
    ->toHaveLineCountLessThan(150)
    ->toHaveSuffix('Exception');

arch('Http')
    ->expect('App\Http')
    ->toBeClasses()
    ->toOnlyBeUsedIn(['App\Http', 'App\Providers']);

arch('Middleware')
    ->expect('App\Http\Middleware')
    ->toBeClasses()
    ->toHaveMethod('handle')
    ->toHaveLineCountLessThan(150);

arch('Controllers')
    ->expect('App\Http\Controllers')
    ->toBeClasses()
    ->not->toHavePublicMethodsBesides(['__construct', '__invoke', 'index', 'show', 'create', 'store', 'edit', 'update', 'destroy', 'middleware'])
    ->toOnlyBeUsedIn('App\Http\Controllers')
    ->toHaveLineCountLessThan(250)
    ->ignoring('App\Http\Controllers\Api')
    ->toHaveSuffix('Controller');

arch('Requests')
    ->expect('App\Http\Requests')
    ->toBeClasses()
    ->toHaveMethod(method: 'rules')
    ->toExtend(FormRequest::class)
    ->toOnlyBeUsedIn('App\Http\Controllers')
    ->toHaveLineCountLessThan(150)
    ->toHaveSuffix('Request');

arch('Resources')
    ->expect('App\Http\Resources')
    ->toBeClasses()
    ->toExtend(JsonResource::class)
    ->toOnlyBeUsedIn('App\Http\Controllers')
    ->toHaveLineCountLessThan(150)
    ->toHaveSuffix('Resource');

arch('Actions')
    ->expect('App\Actions')
    ->toBeClasses()
    ->not->toHavePublicMethodsBesides(['handle'])
    ->toExtendNothing()
    ->toImplementNothing()
    ->toHaveLineCountLessThan(250)
    ->not->toHaveSuffix('Action');

arch('Services')
    ->expect('App\Services')
    ->toBeClasses()
    ->toHaveLineCountLessThan(250)
    ->toHaveSuffix('Service');

arch('Events')
    ->expect('App\Events')
    ->toBeClasses()
    ->toExtendNothing()
    ->toUseTrait(SerializesModels::class)
    ->toHaveLineCountLessThan(100)
    ->not->toHaveSuffix('Event');

arch('Listeners')
    ->expect('App\Listeners')
    ->toBeClasses()
    ->not->toHavePublicMethodsBesides(['__construct', 'handle'])
    ->toHaveLineCountLessThan(100);

arch('Commands')
    ->expect('App\Console\Commands')
    ->toBeClasses()
    ->not->toHavePublicMethodsBesides(['handle'])
    ->toExtend(Command::class)
    ->toImplementNothing()
    ->toHaveLineCountLessThan(150)
    ->toHaveSuffix('Command');

arch('Jobs')
    ->expect('App\Jobs')
    ->toBeClasses()
    ->not->toHavePublicMethodsBesides(['handle'])
    ->toImplement(ShouldQueue::class)
    ->toUseTrait(Dispatchable::class)
    ->toHaveLineCountLessThan(250)
    ->toHaveSuffix('Job');

arch('Mail')
    ->expect('App\Mail')
    ->toBeClasses()
    ->toExtend(Mailable::class)
    ->toImplement(ShouldQueue::class)
    ->toHaveLineCountLessThan(150);

arch('Notifications')
    ->expect('App\Notifications')
    ->toBeClasses()
    ->toExtend(Notification::class)
    ->toHaveLineCountLessThan(200)
    ->toHaveSuffix('Notification');

arch('Models')
    ->expect('App\Models')
    ->toBeClasses()
    ->toExtend(Model::class)
    ->ignoring('App\Models\Scopes')
    ->not->toUseTrait(SoftDeletes::class)
    ->toHaveLineCountLessThan(250)
    ->not->toHaveSuffix('Model');

arch('Queries')
    ->expect('App\Queries')
    ->toBeClasses()
    ->toExtend(Builder::class)
    ->not->toHavePublicMethodsBesides(['__construct', 'builder'])
    ->toHaveLineCountLessThan(150);

arch('Policies')
    ->expect('App\Policies')
    ->toBeClasses()
    ->toHaveLineCountLessThan(150)
    ->toHaveSuffix('Policy');

arch('Providers')
    ->expect('App\Providers')
    ->toBeClasses()
    ->toExtend(ServiceProvider::class)
    ->not->toBeUsed()
    ->toHaveLineCountLessThan(250)
    ->toHaveSuffix('ServiceProvider');

arch('Functions')
    ->expect(['dd', 'ddd', 'dump', 'env', 'exit', 'ray', 'sleep', 'usleep'])
    ->not->toBeUsed();
