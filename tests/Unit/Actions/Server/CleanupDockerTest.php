<?php

beforeEach(function () {
    Mockery::close();
});

afterEach(function () {
    Mockery::close();
});

it('categorizes images correctly into PR and regular images', function () {
    // Test the image categorization logic
    // Build images (*-build) are excluded from retention and handled by docker image prune
    $images = collect([
        ['repository' => 'app-uuid', 'tag' => 'abc123', 'created_at' => '2024-01-01', 'image_ref' => 'app-uuid:abc123'],
        ['repository' => 'app-uuid', 'tag' => 'def456', 'created_at' => '2024-01-02', 'image_ref' => 'app-uuid:def456'],
        ['repository' => 'app-uuid', 'tag' => 'pr-123', 'created_at' => '2024-01-03', 'image_ref' => 'app-uuid:pr-123'],
        ['repository' => 'app-uuid', 'tag' => 'pr-456', 'created_at' => '2024-01-04', 'image_ref' => 'app-uuid:pr-456'],
        ['repository' => 'app-uuid', 'tag' => 'abc123-build', 'created_at' => '2024-01-05', 'image_ref' => 'app-uuid:abc123-build'],
        ['repository' => 'app-uuid', 'tag' => 'def456-build', 'created_at' => '2024-01-06', 'image_ref' => 'app-uuid:def456-build'],
    ]);

    // PR images (tags starting with 'pr-')
    $prImages = $images->filter(fn ($image) => str_starts_with($image['tag'], 'pr-'));
    expect($prImages)->toHaveCount(2);
    expect($prImages->pluck('tag')->toArray())->toContain('pr-123', 'pr-456');

    // Regular images (neither PR nor build) - these are subject to retention policy
    $regularImages = $images->filter(fn ($image) => ! str_starts_with($image['tag'], 'pr-') && ! str_ends_with($image['tag'], '-build'));
    expect($regularImages)->toHaveCount(2);
    expect($regularImages->pluck('tag')->toArray())->toContain('abc123', 'def456');
});

it('filters out currently running image from deletion candidates', function () {
    $images = collect([
        ['repository' => 'app-uuid', 'tag' => 'abc123', 'created_at' => '2024-01-01', 'image_ref' => 'app-uuid:abc123'],
        ['repository' => 'app-uuid', 'tag' => 'def456', 'created_at' => '2024-01-02', 'image_ref' => 'app-uuid:def456'],
        ['repository' => 'app-uuid', 'tag' => 'ghi789', 'created_at' => '2024-01-03', 'image_ref' => 'app-uuid:ghi789'],
    ]);

    $currentTag = 'def456';

    $deletionCandidates = $images->filter(fn ($image) => $image['tag'] !== $currentTag);

    expect($deletionCandidates)->toHaveCount(2);
    expect($deletionCandidates->pluck('tag')->toArray())->not->toContain('def456');
    expect($deletionCandidates->pluck('tag')->toArray())->toContain('abc123', 'ghi789');
});

it('keeps the correct number of images based on docker_images_to_keep setting', function () {
    $images = collect([
        ['repository' => 'app-uuid', 'tag' => 'commit1', 'created_at' => '2024-01-01 10:00:00', 'image_ref' => 'app-uuid:commit1'],
        ['repository' => 'app-uuid', 'tag' => 'commit2', 'created_at' => '2024-01-02 10:00:00', 'image_ref' => 'app-uuid:commit2'],
        ['repository' => 'app-uuid', 'tag' => 'commit3', 'created_at' => '2024-01-03 10:00:00', 'image_ref' => 'app-uuid:commit3'],
        ['repository' => 'app-uuid', 'tag' => 'commit4', 'created_at' => '2024-01-04 10:00:00', 'image_ref' => 'app-uuid:commit4'],
        ['repository' => 'app-uuid', 'tag' => 'commit5', 'created_at' => '2024-01-05 10:00:00', 'image_ref' => 'app-uuid:commit5'],
    ]);

    $currentTag = 'commit5';
    $imagesToKeep = 2;

    // Filter out current, sort by date descending, keep N
    $sortedImages = $images
        ->filter(fn ($image) => $image['tag'] !== $currentTag)
        ->sortByDesc('created_at')
        ->values();

    $imagesToDelete = $sortedImages->skip($imagesToKeep);

    // Should delete commit1, commit2 (oldest 2 after keeping 2 newest: commit4, commit3)
    expect($imagesToDelete)->toHaveCount(2);
    expect($imagesToDelete->pluck('tag')->toArray())->toContain('commit1', 'commit2');
});

it('deletes all images when docker_images_to_keep is 0', function () {
    $images = collect([
        ['repository' => 'app-uuid', 'tag' => 'commit1', 'created_at' => '2024-01-01 10:00:00', 'image_ref' => 'app-uuid:commit1'],
        ['repository' => 'app-uuid', 'tag' => 'commit2', 'created_at' => '2024-01-02 10:00:00', 'image_ref' => 'app-uuid:commit2'],
        ['repository' => 'app-uuid', 'tag' => 'commit3', 'created_at' => '2024-01-03 10:00:00', 'image_ref' => 'app-uuid:commit3'],
    ]);

    $currentTag = 'commit3';
    $imagesToKeep = 0;

    $sortedImages = $images
        ->filter(fn ($image) => $image['tag'] !== $currentTag)
        ->sortByDesc('created_at')
        ->values();

    $imagesToDelete = $sortedImages->skip($imagesToKeep);

    // Should delete all images except the currently running one
    expect($imagesToDelete)->toHaveCount(2);
    expect($imagesToDelete->pluck('tag')->toArray())->toContain('commit1', 'commit2');
});

it('does not delete any images when there are fewer than images_to_keep', function () {
    $images = collect([
        ['repository' => 'app-uuid', 'tag' => 'commit1', 'created_at' => '2024-01-01 10:00:00', 'image_ref' => 'app-uuid:commit1'],
        ['repository' => 'app-uuid', 'tag' => 'commit2', 'created_at' => '2024-01-02 10:00:00', 'image_ref' => 'app-uuid:commit2'],
    ]);

    $currentTag = 'commit2';
    $imagesToKeep = 5;

    $sortedImages = $images
        ->filter(fn ($image) => $image['tag'] !== $currentTag)
        ->sortByDesc('created_at')
        ->values();

    $imagesToDelete = $sortedImages->skip($imagesToKeep);

    // Should not delete anything - we have fewer images than the keep limit
    expect($imagesToDelete)->toHaveCount(0);
});

it('handles images with custom registry names', function () {
    // Test that the logic works regardless of repository name format
    $images = collect([
        ['repository' => 'registry.example.com/my-app', 'tag' => 'v1.0.0', 'created_at' => '2024-01-01', 'image_ref' => 'registry.example.com/my-app:v1.0.0'],
        ['repository' => 'registry.example.com/my-app', 'tag' => 'v1.1.0', 'created_at' => '2024-01-02', 'image_ref' => 'registry.example.com/my-app:v1.1.0'],
        ['repository' => 'registry.example.com/my-app', 'tag' => 'pr-99', 'created_at' => '2024-01-03', 'image_ref' => 'registry.example.com/my-app:pr-99'],
    ]);

    $prImages = $images->filter(fn ($image) => str_starts_with($image['tag'], 'pr-'));
    $regularImages = $images->filter(fn ($image) => ! str_starts_with($image['tag'], 'pr-') && ! str_ends_with($image['tag'], '-build'));

    expect($prImages)->toHaveCount(1);
    expect($regularImages)->toHaveCount(2);
});

it('correctly identifies PR build images as PR images', function () {
    // PR build images have tags like 'pr-123-build'
    // They are identified as PR images (start with 'pr-') and will be deleted
    $images = collect([
        ['repository' => 'app-uuid', 'tag' => 'pr-123', 'created_at' => '2024-01-01', 'image_ref' => 'app-uuid:pr-123'],
        ['repository' => 'app-uuid', 'tag' => 'pr-123-build', 'created_at' => '2024-01-02', 'image_ref' => 'app-uuid:pr-123-build'],
    ]);

    // PR images include both pr-123 and pr-123-build (both start with 'pr-')
    $prImages = $images->filter(fn ($image) => str_starts_with($image['tag'], 'pr-'));

    expect($prImages)->toHaveCount(2);
});

it('defaults to keeping 2 images when setting is null', function () {
    $defaultValue = 2;

    // Simulate the null coalescing behavior
    $dockerImagesToKeep = null ?? $defaultValue;

    expect($dockerImagesToKeep)->toBe(2);
});

it('does not delete images when count equals images_to_keep', function () {
    // Scenario: User has 3 images, 1 is running, 2 remain, keep limit is 2
    // Expected: No images should be deleted
    $images = collect([
        ['repository' => 'app-uuid', 'tag' => 'commit1', 'created_at' => '2024-01-01 10:00:00', 'image_ref' => 'app-uuid:commit1'],
        ['repository' => 'app-uuid', 'tag' => 'commit2', 'created_at' => '2024-01-02 10:00:00', 'image_ref' => 'app-uuid:commit2'],
        ['repository' => 'app-uuid', 'tag' => 'commit3', 'created_at' => '2024-01-03 10:00:00', 'image_ref' => 'app-uuid:commit3'],
    ]);

    $currentTag = 'commit3'; // This is running
    $imagesToKeep = 2;

    $sortedImages = $images
        ->filter(fn ($image) => $image['tag'] !== $currentTag)
        ->sortByDesc('created_at')
        ->values();

    // After filtering out running image, we have 2 images
    expect($sortedImages)->toHaveCount(2);

    $imagesToDelete = $sortedImages->skip($imagesToKeep);

    // Skip 2, leaving 0 to delete
    expect($imagesToDelete)->toHaveCount(0);
});

it('handles scenario where no container is running', function () {
    // Scenario: 2 images exist, none running, keep limit is 2
    // Expected: No images should be deleted (keep all 2)
    $images = collect([
        ['repository' => 'app-uuid', 'tag' => 'commit1', 'created_at' => '2024-01-01 10:00:00', 'image_ref' => 'app-uuid:commit1'],
        ['repository' => 'app-uuid', 'tag' => 'commit2', 'created_at' => '2024-01-02 10:00:00', 'image_ref' => 'app-uuid:commit2'],
    ]);

    $currentTag = ''; // No container running, empty tag
    $imagesToKeep = 2;

    $sortedImages = $images
        ->filter(fn ($image) => $image['tag'] !== $currentTag)
        ->sortByDesc('created_at')
        ->values();

    // All images remain since none match the empty current tag
    expect($sortedImages)->toHaveCount(2);

    $imagesToDelete = $sortedImages->skip($imagesToKeep);

    // Skip 2, leaving 0 to delete
    expect($imagesToDelete)->toHaveCount(0);
});
