<?php

namespace Tests\Feature;

use App\Services\OAuth\OidcProvider;
use Tests\TestCase;

class OidcTest extends TestCase
{
    public function testOidcRedirect()
    {
        $response = $this->get('/auth/oidc/redirect');
        $response->assertRedirect();
    }

    public function testOidcCallback()
    {
        $response = $this->get('/auth/oidc/callback');
        $response->assertRedirect();
    }
}