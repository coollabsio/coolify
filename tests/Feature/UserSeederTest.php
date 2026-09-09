<?php

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Schema\Blueprint;

it('preserves the root ID and lets the database generate normal user IDs', function (string $connectionName, int $existingUsers) {
    if ($connectionName === 'pgsql' && ! getenv('TEST_POSTGRES_SEEDER')) {
        $this->markTestSkipped('Set TEST_POSTGRES_SEEDER=1 to test PostgreSQL using the configured pgsql connection.');
    }

    $originalConnectionName = config('database.default');
    config()->set('database.default', $connectionName);
    $connection = (new User)->getConnection();
    $connection->beginTransaction();

    try {
        // A temporary table isolates this test from existing development users and their sequence.
        $connection->getSchemaBuilder()->create('users', function (Blueprint $table) {
            $table->temporary();
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        User::withoutEvents(function () use ($existingUsers) {
            User::factory()->count($existingUsers)->create();

            $this->seed(UserSeeder::class);

            expect(User::query()->whereIn('email', ['test@example.com', 'test2@example.com', 'test3@example.com'])->orderBy('id')->pluck('email', 'id')->all())->toBe([
                0 => 'test@example.com',
                $existingUsers + 1 => 'test2@example.com',
                $existingUsers + 2 => 'test3@example.com',
            ]);

            expect(User::factory()->create()->id)->toBe($existingUsers + 3)
                ->and(User::factory()->create()->id)->toBe($existingUsers + 4);
        });
    } finally {
        $connection->rollBack();
        config()->set('database.default', $originalConnectionName);
    }
})->with(['testing', 'pgsql'])->with([0, 5]);
