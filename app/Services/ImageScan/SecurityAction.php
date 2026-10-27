<?php

namespace App\Services\ImageScan;

use App\Enums\SecurityActionType;

/**
 * One action that fixes relevant findings of a scanned image (spec 15.5).
 */
final readonly class SecurityAction
{
    /**
     * @param  string  $sentence  The text of spec 15.5.
     * @param  ?string  $target  The tag (update_image, pull_latest), the new base image (change_base), or the package.
     * @param  ?string  $copy  Text for the copy button: `FROM {new}` or `{package} {fixed_in}`.
     * @param  list<string>  $fixedIds  The IDs of the findings it fixes.
     * @param  bool  $button  Coolify can do it with a button (update_image, pull_latest, rebuild). Callers still authorize.
     * @param  ?string  $image  The scanned image it belongs to.
     * @param  ?string  $ref  The candidate image to switch to (update_image, pull_latest).
     */
    public function __construct(
        public SecurityActionType $type,
        public string $sentence,
        public ?string $target,
        public ?string $copy,
        public array $fixedIds,
        public bool $button,
        public ?string $image,
        public ?string $ref = null,
    ) {}
}
