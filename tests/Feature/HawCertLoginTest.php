<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

it('allows login via HawCert access key', function () {
    config()->set('services.hawcert.base_url', 'https://hawcert.test');

    $user = User::factory()->create([
        'email' => 'cert-user@example.com',
    ]);

    Http::fake([
        'https://hawcert.test/api/validate-key' => Http::response([
            'success' => true,
            'valid' => true,
            'user' => [
                'email' => 'cert-user@example.com',
                'id' => 123,
                'name' => 'Cert User',
            ],
            'certificate' => [
                'email' => 'cert-user@example.com',
            ],
        ], 200),
    ]);

    $response = $this->post(route('login.hawcert'), [
        'key' => 'ak_'.str_repeat('a', 48),
    ]);

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

it('rejects HawCert login when validation fails', function () {
    config()->set('services.hawcert.base_url', 'https://hawcert.test');

    Http::fake([
        'https://hawcert.test/api/validate-key' => Http::response([
            'success' => false,
            'message' => 'Key de acceso no encontrada',
        ], 404),
    ]);

    $response = $this->from(route('login'))->post(route('login.hawcert'), [
        'key' => 'ak_'.str_repeat('b', 48),
    ]);

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error');
    $this->assertGuest();
});

