<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property-read string $id
 * @property-read string $name
 * @property-read string|null $display_name
 * @property-read string $email
 * @property-read string|null $notification_email
 * @property-read CarbonInterface|null $email_verified_at
 * @property-read CarbonInterface|null $notification_email_verified_at
 * @property-read string $password
 * @property-read string|null $remember_token
 * @property-read string|null $two_factor_secret
 * @property-read string|null $two_factor_recovery_codes
 * @property-read CarbonInterface|null $two_factor_confirmed_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasUlids;

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'name' => 'string',
            'display_name' => 'string',
            'email' => 'string',
            'notification_email' => 'string',
            'email_verified_at' => 'datetime',
            'notification_email_verified_at' => 'datetime',
            'password' => 'hashed',
            'remember_token' => 'string',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
