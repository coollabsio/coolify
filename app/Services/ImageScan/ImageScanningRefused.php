<?php

namespace App\Services\ImageScan;

use RuntimeException;

/**
 * SetServerImageScanning did not turn scanning on. The message is shown to the user.
 */
final class ImageScanningRefused extends RuntimeException {}
