<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('invalidates sessions when password changes', function () {
    // Create a user
    $user = User::factory()->create([
        'password' => Hash::make('old-password'),
    ]);

    // Create fake session records for the user
    DB::table('sessions')->insert([
        [
            'id' => 'session-1',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test Browser',
            'payload' => base64_encode('test-payload-1'),
            'last_activity' => now()->timestamp,
        ],
        [
            'id' => 'session-2',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test Browser',
            'payload' => base64_encode('test-payload-2'),
            'last_activity' => now()->timestamp,
        ],
    ]);

    // Verify sessions exist
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(2);

    // Change password
    $user->password = Hash::make('new-password');
    $user->save();

    // Verify all sessions for this user were deleted
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

it('does not invalidate sessions when password is unchanged', function () {
    // Create a user
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    // Create fake session records for the user
    DB::table('sessions')->insert([
        [
            'id' => 'session-1',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test Browser',
            'payload' => base64_encode('test-payload'),
            'last_activity' => now()->timestamp,
        ],
    ]);

    // Update other user fields (not password)
    $user->name = 'New Name';
    $user->save();

    // Verify session still exists
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
});

it('does not invalidate sessions when password is set to same value', function () {
    // Create a user with a specific password
    $hashedPassword = Hash::make('password');
    $user = User::factory()->create([
        'password' => $hashedPassword,
    ]);

    // Create fake session records for the user
    DB::table('sessions')->insert([
        [
            'id' => 'session-1',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test Browser',
            'payload' => base64_encode('test-payload'),
            'last_activity' => now()->timestamp,
        ],
    ]);

    // Set password to the same value
    $user->password = $hashedPassword;
    $user->save();

    // Verify session still exists (password didn't actually change)
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
});

it('invalidates sessions only for the user whose password changed', function () {
    // Create two users
    $user1 = User::factory()->create([
        'password' => Hash::make('password1'),
    ]);
    $user2 = User::factory()->create([
        'password' => Hash::make('password2'),
    ]);

    // Create sessions for both users
    DB::table('sessions')->insert([
        [
            'id' => 'session-user1',
            'user_id' => $user1->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test Browser',
            'payload' => base64_encode('test-payload-1'),
            'last_activity' => now()->timestamp,
        ],
        [
            'id' => 'session-user2',
            'user_id' => $user2->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test Browser',
            'payload' => base64_encode('test-payload-2'),
            'last_activity' => now()->timestamp,
        ],
    ]);

    // Change password for user1 only
    $user1->password = Hash::make('new-password1');
    $user1->save();

    // Verify user1's sessions were deleted but user2's remain
    expect(DB::table('sessions')->where('user_id', $user1->id)->count())->toBe(0);
    expect(DB::table('sessions')->where('user_id', $user2->id)->count())->toBe(1);
});
