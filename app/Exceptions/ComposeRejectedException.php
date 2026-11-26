<?php

namespace App\Exceptions;

/**
 * `docker compose config` on the server rejected a generated Compose file.
 */
class ComposeRejectedException extends NonReportableException {}
