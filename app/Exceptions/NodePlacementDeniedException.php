<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The user may not create or place a cluster resource there: no write access on the environment,
 * or no `NodePolicy::placeResource` on the server (decision 0029). The pages show the message as
 * for every RuntimeException. The API answers 403.
 */
class NodePlacementDeniedException extends RuntimeException {}
