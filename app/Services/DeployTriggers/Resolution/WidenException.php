<?php

namespace App\Services\DeployTriggers\Resolution;

use RuntimeException;

/**
 * A limit or resolver cannot be sure, so it must not narrow the trigger set.
 */
class WidenException extends RuntimeException {}
