<?php

namespace App\Services\OAuth;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\OAuthProvider;

class OidcProvider extends OAuthProvider
{
    protected function mapUserToObject(array $user)
    {
        return (new User())->setRawAttributes($user);
    }

    public function redirect()
    {
        $url = $this->getAuthorizationUrl();
        return redirect()->to($url);
    }

    public function getUserByToken($token)
    {
        $response = Http::withToken($token)->get($this->getUserInfoUrl());
        $user = json_decode($response->body(), true);
        return $user;
    }

    protected function getAuthorizationUrl()
    {
        $url = $this->clientId.' '.$this->clientSecret.' '.$this->redirectUrl;
        return 'https://'.$this->domain.'/authorize?'.http_build_query($url);
    }

    protected function getUserInfoUrl()
    {
        return 'https://'.$this->domain.'/userinfo';
    }
}