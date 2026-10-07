<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Remote secrets of a resource could not be resolved, for example because the secret manager is
 * unreachable or a referenced key does not exist. The message names no secret values, so it can be
 * shown to the user.
 */
class RemoteSecretException extends RuntimeException {}
