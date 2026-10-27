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

    /**
     * @return array{type: string, sentence: string, target: ?string, copy: ?string, fixed_ids: list<string>, button: bool, image: ?string, ref: ?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'sentence' => $this->sentence,
            'target' => $this->target,
            'copy' => $this->copy,
            'fixed_ids' => $this->fixedIds,
            'button' => $this->button,
            'image' => $this->image,
            'ref' => $this->ref,
        ];
    }

    /**
     * Null for data that is not an action.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $type = SecurityActionType::tryFrom((string) ($data['type'] ?? ''));
        if ($type === null) {
            return null;
        }

        return new self(
            type: $type,
            sentence: (string) ($data['sentence'] ?? ''),
            target: isset($data['target']) ? (string) $data['target'] : null,
            copy: isset($data['copy']) ? (string) $data['copy'] : null,
            fixedIds: array_values(array_map('strval', (array) ($data['fixed_ids'] ?? []))),
            button: (bool) ($data['button'] ?? false),
            image: isset($data['image']) ? (string) $data['image'] : null,
            ref: isset($data['ref']) ? (string) $data['ref'] : null,
        );
    }
}
