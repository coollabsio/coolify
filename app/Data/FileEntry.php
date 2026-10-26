<?php

namespace App\Data;

class FileEntry
{
    public function __construct(
        public string $name,
        public string $type,
        public int $size,
        public int $mtime,
        public string $perms = '',
        public string $owner = '',
        public string $group = '',
    ) {}

    public function isDir(): bool
    {
        return $this->type === 'dir';
    }

    public function isEditableCandidate(): bool
    {
        return ! $this->isDir();
    }

    /**
     * @param  array<int, self>  $entries
     * @return array<int, self>
     */
    public static function sort(array $entries): array
    {
        usort($entries, function (self $a, self $b) {
            if ($a->isDir() !== $b->isDir()) {
                return $a->isDir() ? -1 : 1;
            }

            return strcasecmp($a->name, $b->name);
        });

        return $entries;
    }
}
