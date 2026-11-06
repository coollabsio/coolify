<?php

namespace App\Services\ImageScan;

use RuntimeException;

/**
 * A background candidate check found the server lock busy (or a deploy gate waiting for it) and stopped without
 * storing a result, so its job can retry later.
 */
final class ScanLockBusy extends RuntimeException {}
