<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The container cannot run `sh` (scratch or distroless images), so the
 * file browser cannot list or change its files with docker exec.
 */
class ContainerShellUnavailableException extends RuntimeException {}
