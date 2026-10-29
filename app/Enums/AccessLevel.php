<?php

namespace App\Enums;

enum AccessLevel: string
{
    case Read = 'read';
    case ReadSensitive = 'read:sensitive';
    case Deploy = 'deploy';
    case Write = 'write';
    case Delete = 'delete';
    case Root = 'root';

    /**
     * The levels that a grant of this level also gives.
     *
     * Write includes ReadSensitive because a user who can change a resource
     * can always read its secrets back through the compose file or a deploy.
     *
     * @return list<self>
     */
    public function includes(): array
    {
        return match ($this) {
            self::Root => self::cases(),
            self::Write => [self::Write, self::Deploy, self::ReadSensitive, self::Read],
            self::Delete => [self::Delete, self::Read],
            self::Deploy => [self::Deploy, self::Read],
            self::ReadSensitive => [self::ReadSensitive, self::Read],
            self::Read => [self::Read],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Read',
            self::ReadSensitive => 'Read secrets',
            self::Deploy => 'Deploy',
            self::Write => 'Write',
            self::Delete => 'Delete',
            self::Root => 'Full access',
        };
    }

    /**
     * Parse stored level or token ability strings. Unknown values are ignored.
     *
     * @param  iterable<string>  $values
     * @return list<self>
     */
    public static function parseMany(iterable $values): array
    {
        $levels = [];
        foreach ($values as $value) {
            $level = self::tryFrom((string) $value);
            if ($level !== null && ! in_array($level, $levels, true)) {
                $levels[] = $level;
            }
        }

        return $levels;
    }
}
