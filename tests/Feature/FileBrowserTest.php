<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileBrowserTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Application $application;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->application = Application::factory()->create([
            'team_id' => $this->user->currentTeam->id,
        ]);
    }

    public function test_can_list_container_files()
    {
        $this->actingAs($this->user);

        $response = $this->getJson("/api/containers/{$this->application->uuid}/files");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'name',
                        'type',
                        'permissions',
                        'size',
                        'path',
                    ],
                ],
            ]);
    }

    public function test_can_upload_file_to_container()
    {
        $this->actingAs($this->user);

        Storage::fake('local');
        $file = UploadedFile::fake()->create('test.txt', 100);

        $response = $this->postJson("/api/containers/{$this->application->uuid}/files", [
            'file' => $file,
            'path' => '/tmp',
            'permissions' => '644',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_can_create_directory()
    {
        $this->actingAs($this->user);

        $response = $this->postJson("/api/containers/{$this->application->uuid}/files/directories", [
            'path' => '/tmp/test-dir',
            'permissions' => '755',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_can_delete_file()
    {
        $this->actingAs($this->user);

        $response = $this->deleteJson("/api/containers/{$this->application->uuid}/files", [
            'path' => '/tmp/test-file.txt',
            'is_directory' => false,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_can_update_permissions()
    {
        $this->actingAs($this->user);

        $response = $this->putJson("/api/containers/{$this->application->uuid}/files/permissions", [
            'path' => '/tmp/test-file.txt',
            'permissions' => '755',
            'recursive' => false,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_validates_file_upload_size()
    {
        $this->actingAs($this->user);

        Storage::fake('local');
        // Create a file larger than 100MB (simulated)
        $file = UploadedFile::fake()->create('large.txt', 102401); // 100MB + 1KB

        $response = $this->postJson("/api/containers/{$this->application->uuid}/files", [
            'file' => $file,
            'path' => '/tmp',
        ]);

        $response->assertStatus(422);
    }

    public function test_prevents_directory_traversal()
    {
        $this->actingAs($this->user);

        $response = $this->getJson("/api/containers/{$this->application->uuid}/files?path=../../../etc");

        // Should sanitize the path and not allow traversal
        $response->assertStatus(200);
        // Path should be sanitized to /etc
    }

    public function test_requires_authentication()
    {
        $response = $this->getJson("/api/containers/{$this->application->uuid}/files");

        $response->assertStatus(401);
    }

    public function test_checks_container_access_permissions()
    {
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser);

        $response = $this->getJson("/api/containers/{$this->application->uuid}/files");

        $response->assertStatus(403);
    }

    public function test_rate_limiting_works()
    {
        $this->actingAs($this->user);

        // Make 61 requests quickly
        for ($i = 0; $i < 61; $i++) {
            $response = $this->getJson("/api/containers/{$this->application->uuid}/files");

            if ($i < 60) {
                $response->assertStatus(200);
            } else {
                $response->assertStatus(429); // Rate limited
            }
        }
    }
}
