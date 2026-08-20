<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate([
        'id' => 0,
        'is_registration_enabled' => true,
    ]);

    // The auth views are rendered directly, so share the bag the error
    // middleware would normally provide to the shared form components.
    View::share('errors', new ViewErrorBag);
});

it('renders the auth shell with the dashboard layer-card anatomy', function () {
    $html = Blade::render(
        '<x-auth.shell title="Welcome back" description="Sign in.">'
        .'<p>form</p>'
        .'<x-slot:footer>footer</x-slot:footer>'
        .'</x-auth.shell>'
    );

    expect($html)
        // flat canvas shell wrapping the layer-card shape
        ->toContain('auth-shell')
        ->toContain('auth-card')
        // brand strip mirrors the application top bar
        ->toContain('auth-card-brand')
        ->toContain('/coolify-logo.svg')
        // nested body panel carries the heading and the form
        ->toContain('auth-card-body')
        ->toContain('auth-card-heading')
        ->toContain('Welcome back')
        ->toContain('Sign in.')
        ->toContain('auth-card-footer')
        ->toContain('footer');

    // The brand belongs to the strip, not to the page heading.
    expect($html)->not->toContain('<h1>Coolify</h1>');
});

it('renders the login surface with its own heading and the brand strip', function () {
    $html = view('auth.login', [
        'enabled_oauth_providers' => collect(),
        'is_registration_enabled' => true,
    ])->render();

    expect($html)
        ->toContain('auth-card-brand')
        ->toContain('Welcome back')
        ->toContain('Sign in to manage your applications and infrastructure.')
        ->toContain('Login');
});

it('titles the registration surface by what the account does', function (bool $isFirstUser, string $title, string $description) {
    $html = view('auth.register', ['isFirstUser' => $isFirstUser])->render();

    expect($html)
        ->toContain('auth-card-brand')
        ->toContain($title)
        ->toContain($description);
})->with([
    'root account' => [true, 'Set up your instance', 'Create the root account for this instance.'],
    'invited member' => [false, 'Create your account', 'Create your account to get started.'],
]);
