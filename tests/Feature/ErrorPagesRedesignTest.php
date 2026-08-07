<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('renders redesigned error pages with shared shell and actions', function (string $view, string $code, string $title) {
    $exception = new HttpException((int) $code, 'Test error message');

    $html = view($view, ['exception' => $exception])->render();

    expect($html)
        ->toContain('error-page-body')
        ->toContain('error-shell')
        ->toContain('error-code')
        ->toContain('error-title')
        ->toContain('error-actions')
        ->toContain($code)
        ->toContain($title)
        ->toContain('Contact support')
        ->toContain(config('constants.urls.contact'));
})->with([
    '400' => ['errors.400', '400', 'Bad request'],
    '401' => ['errors.401', '401', 'You shall not pass!'],
    '402' => ['errors.402', '402', 'Payment required'],
    '403' => ['errors.403', '403', 'You shall not pass!'],
    '404' => ['errors.404', '404', 'How did you get here?'],
    '419' => ['errors.419', '419', 'This page is definitely old, not like you!'],
    '429' => ['errors.429', '429', 'Woah, slow down there!'],
    '500' => ['errors.500', '500', 'Wait, this is not cool...'],
    '503' => ['errors.503', '503', 'We are working on serious things.'],
]);

it('includes go back and dashboard actions on standard error pages', function () {
    $exception = new HttpException(404, 'Not found');

    $html = view('errors.404', ['exception' => $exception])->render();

    expect($html)
        ->toContain('Go back')
        ->toContain('Dashboard')
        ->toContain(route('dashboard'));
});

it('uses login as primary action on session expired page', function () {
    $exception = new HttpException(419, 'Page expired');

    $html = view('errors.419', ['exception' => $exception])->render();

    expect($html)
        ->toContain('/login')
        ->toContain('Back to login')
        ->toContain('Using a reverse proxy or Cloudflare Tunnel?')
        ->toContain('x-data="{ open: false }"')
        ->toContain('x-on:click="open = !open"')
        ->toContain('livewire.js')
        ->not->toContain('>Dashboard</');
});

it('renders contact support as a button', function () {
    $exception = new HttpException(404, 'Not found');

    $html = view('errors.404', ['exception' => $exception])->render();

    expect($html)
        ->toContain('href="'.config('constants.urls.contact').'"')
        ->toMatch('/<button[^>]*>\s*Contact support/s');
});

it('shows purified exception message on 500 page', function () {
    $exception = new RuntimeException('Database connection failed');

    $html = view('errors.500', ['exception' => $exception])->render();

    expect($html)
        ->toContain('error-code-danger')
        ->toContain('error-message')
        ->toContain('Database connection failed');
});
