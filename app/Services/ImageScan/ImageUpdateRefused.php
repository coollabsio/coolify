<?php

namespace App\Services\ImageScan;

use RuntimeException;

/**
 * UpdateResourceImage did not change anything. The message is shown to the user.
 */
final class ImageUpdateRefused extends RuntimeException {}
