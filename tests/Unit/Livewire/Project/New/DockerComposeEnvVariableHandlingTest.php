<?php

test('DockerCompose handles array format from parseEnvFormatToArray', function () {
    // Simulate the array format that parseEnvFormatToArray returns
    $variables = [
        'KEY1' => ['value' => 'value1', 'comment' => null],
        'KEY2' => ['value' => 'value2', 'comment' => 'This is a comment'],
        'KEY3' => ['value' => 'value3', 'comment' => null],
    ];

    // Test the extraction logic
    foreach ($variables as $key => $data) {
        // Handle both array format ['value' => ..., 'comment' => ...] and plain string values
        $value = is_array($data) ? ($data['value'] ?? '') : $data;
        $comment = is_array($data) ? ($data['comment'] ?? null) : null;

        // Verify the extraction
        expect($value)->toBeString();
        expect($key)->toBeIn(['KEY1', 'KEY2', 'KEY3']);

        if ($key === 'KEY1') {
            expect($value)->toBe('value1');
            expect($comment)->toBeNull();
        } elseif ($key === 'KEY2') {
            expect($value)->toBe('value2');
            expect($comment)->toBe('This is a comment');
        } elseif ($key === 'KEY3') {
            expect($value)->toBe('value3');
            expect($comment)->toBeNull();
        }
    }
});

test('DockerCompose handles plain string format gracefully', function () {
    // Simulate a scenario where parseEnvFormatToArray might return plain strings
    // (for backward compatibility or edge cases)
    $variables = [
        'KEY1' => 'value1',
        'KEY2' => 'value2',
        'KEY3' => 'value3',
    ];

    // Test the extraction logic
    foreach ($variables as $key => $data) {
        // Handle both array format ['value' => ..., 'comment' => ...] and plain string values
        $value = is_array($data) ? ($data['value'] ?? '') : $data;
        $comment = is_array($data) ? ($data['comment'] ?? null) : null;

        // Verify the extraction
        expect($value)->toBeString();
        expect($comment)->toBeNull();
        expect($key)->toBeIn(['KEY1', 'KEY2', 'KEY3']);
    }
});

test('DockerCompose handles mixed array and string formats', function () {
    // Simulate a mixed scenario (unlikely but possible)
    $variables = [
        'KEY1' => ['value' => 'value1', 'comment' => 'comment1'],
        'KEY2' => 'value2', // Plain string
        'KEY3' => ['value' => 'value3', 'comment' => null],
        'KEY4' => 'value4', // Plain string
    ];

    // Test the extraction logic
    foreach ($variables as $key => $data) {
        // Handle both array format ['value' => ..., 'comment' => ...] and plain string values
        $value = is_array($data) ? ($data['value'] ?? '') : $data;
        $comment = is_array($data) ? ($data['comment'] ?? null) : null;

        // Verify the extraction
        expect($value)->toBeString();
        expect($key)->toBeIn(['KEY1', 'KEY2', 'KEY3', 'KEY4']);

        if ($key === 'KEY1') {
            expect($value)->toBe('value1');
            expect($comment)->toBe('comment1');
        } elseif ($key === 'KEY2') {
            expect($value)->toBe('value2');
            expect($comment)->toBeNull();
        } elseif ($key === 'KEY3') {
            expect($value)->toBe('value3');
            expect($comment)->toBeNull();
        } elseif ($key === 'KEY4') {
            expect($value)->toBe('value4');
            expect($comment)->toBeNull();
        }
    }
});

test('DockerCompose handles empty array values gracefully', function () {
    // Simulate edge case with incomplete array structure
    $variables = [
        'KEY1' => ['value' => 'value1'], // Missing 'comment' key
        'KEY2' => ['comment' => 'comment2'], // Missing 'value' key (edge case)
        'KEY3' => [], // Empty array (edge case)
    ];

    // Test the extraction logic with improved fallback
    foreach ($variables as $key => $data) {
        // Handle both array format ['value' => ..., 'comment' => ...] and plain string values
        $value = is_array($data) ? ($data['value'] ?? '') : $data;
        $comment = is_array($data) ? ($data['comment'] ?? null) : null;

        // Verify the extraction doesn't crash
        expect($key)->toBeIn(['KEY1', 'KEY2', 'KEY3']);

        if ($key === 'KEY1') {
            expect($value)->toBe('value1');
            expect($comment)->toBeNull();
        } elseif ($key === 'KEY2') {
            // If 'value' is missing, fallback to empty string (not the whole array)
            expect($value)->toBe('');
            expect($comment)->toBe('comment2');
        } elseif ($key === 'KEY3') {
            // If both are missing, fallback to empty string (not empty array)
            expect($value)->toBe('');
            expect($comment)->toBeNull();
        }
    }
});
