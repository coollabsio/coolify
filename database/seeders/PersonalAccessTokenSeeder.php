<?php

namespace Database\Seeders;

use App\Models\PersonalAccessToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PersonalAccessTokenSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Only run in development environment
        if (app()->environment('production')) {
            $this->command->warn('Skipping PersonalAccessTokenSeeder in production environment');

            return;
        }

        // Get the first user (usually the admin user created during setup)
        $user = User::find(0);

        if (! $user) {
            $this->command->warn('No user found. Please run UserSeeder first.');

            return;
        }

        // Get the user's first team
        $team = $user->teams()->first();

        if (! $team) {
            $this->command->warn('No team found for user. Cannot create API tokens.');

            return;
        }

        // Define test tokens with different scopes
        $testTokens = [
            [
                'name' => 'Development Root Token',
                'token' => 'root',
                'abilities' => ['root'],
            ],
            [
                'name' => 'Development Read Token',
                'token' => 'read',
                'abilities' => ['read'],
            ],
            [
                'name' => 'Development Read Sensitive Token',
                'token' => 'read-sensitive',
                'abilities' => ['read', 'read:sensitive'],
            ],
            [
                'name' => 'Development Write Token',
                'token' => 'write',
                'abilities' => ['write'],
            ],
            [
                'name' => 'Development Write Sensitive Token',
                'token' => 'write-sensitive',
                'abilities' => ['write', 'write:sensitive'],
            ],
            [
                'name' => 'Development Deploy Token',
                'token' => 'deploy',
                'abilities' => ['deploy'],
            ],
        ];

        // First, remove all existing development tokens for this user
        $deletedCount = PersonalAccessToken::where('tokenable_id', $user->id)
            ->where('tokenable_type', get_class($user))
            ->whereIn('name', array_column($testTokens, 'name'))
            ->delete();

        if ($deletedCount > 0) {
            $this->command->info("Removed {$deletedCount} existing development token(s).");
        }

        // Now create fresh tokens
        foreach ($testTokens as $tokenData) {
            // Create the token with a simple format: Bearer {scope}
            // The token format in the database is the hash of the plain text token
            $plainTextToken = $tokenData['token'];

            PersonalAccessToken::create([
                'tokenable_type' => get_class($user),
                'tokenable_id' => $user->id,
                'name' => $tokenData['name'],
                'token' => hash('sha256', $plainTextToken),
                'abilities' => $tokenData['abilities'],
                'team_id' => $team->id,
            ]);

            $this->command->info("Created token '{$tokenData['name']}' with Bearer token: {$plainTextToken}");
        }

        $this->command->info('');
        $this->command->info('Test API tokens created successfully!');
        $this->command->info('You can use these tokens in development as:');
        $this->command->info('  Bearer root           - Root access');
        $this->command->info('  Bearer read           - Read only access');
        $this->command->info('  Bearer read-sensitive - Read with sensitive data access');
        $this->command->info('  Bearer write          - Write access');
        $this->command->info('  Bearer write-sensitive - Write with sensitive data access');
        $this->command->info('  Bearer deploy         - Deploy access');
    }
}
