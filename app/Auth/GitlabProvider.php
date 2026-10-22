<?php

namespace App\Auth;

use GuzzleHttp\RequestOptions;
use Laravel\Socialite\Two\GitlabProvider as SocialiteGitlabProvider;

/**
 * Socialite's GitLab provider reads the user from /api/v3/user, which GitLab
 * removed in 11.0 (410 Gone). Read it from API v4 instead.
 */
class GitlabProvider extends SocialiteGitlabProvider
{
    /**
     * @param  string  $token
     * @return array<string, mixed>
     */
    protected function getUserByToken($token)
    {
        $response = $this->getHttpClient()->get($this->host.'/api/v4/user', [
            RequestOptions::HEADERS => ['Authorization' => 'Bearer '.$token],
        ]);

        return json_decode((string) $response->getBody(), true);
    }
}
