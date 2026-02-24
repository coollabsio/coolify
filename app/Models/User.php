<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property-read int $id
 * @property-read string $name
 * @property-read string $email
 * @property-read CarbonInterface|null $email_verified_at
 * @property-read string $password
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'name' => 'string',
            'email' => 'string',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
