<?php

use App\Http\Controllers\Webhook\Bitbucket;
use App\Http\Controllers\Webhook\Gitea;
use App\Http\Controllers\Webhook\Github;
use App\Http\Controllers\Webhook\Gitlab;
use App\Http\Controllers\Webhook\Stripe;
use Illuminate\Support\Facades\Route;

Route::get('/source/github/redirect', [Github::class, 'redirect']);
Route::get('/source/github/install', [Github::class, 'install']);
Route::post('/source/github/events', [Github::class, 'normal']);
Route::post('/source/github/events/manual', [Github::class, 'manual']);

Route::post('/source/gitlab/events/manual', [Gitlab::class, 'manual']);

Route::post('/source/bitbucket/events/manual', [Bitbucket::class, 'manual']);

Route::post('/source/gitea/events/manual', [Gitea::class, 'manual']);

Route::post('/payments/stripe/events', [Stripe::class, 'events']);
