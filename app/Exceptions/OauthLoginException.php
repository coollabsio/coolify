<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A denied OAuth login with a translation key for the message shown on the
 * login page. The exception message is only written to the log.
 */
class OauthLoginException extends HttpException
{
    public function __construct(
        string $message,
        public readonly string $userMessageKey = 'auth.failed.oauth'
    ) {
        parent::__construct(403, $message);
    }
}
