<?php

namespace Tests\Feature;

use Tests\TestCase;

class Utf8HandlingTest extends TestCase
{
    public function test_sanitize_utf8_text_handles_malformed_utf8()
    {
        // Test with valid UTF-8
        $validUtf8 = 'Hello World! 🚀';
        $this->assertEquals($validUtf8, sanitize_utf8_text($validUtf8));

        // Test with empty string
        $this->assertEquals('', sanitize_utf8_text(''));

        // Test with malformed UTF-8 (binary data)
        $malformedUtf8 = "Hello\x80\x81\x82World";
        $sanitized = sanitize_utf8_text($malformedUtf8);
        $this->assertTrue(mb_check_encoding($sanitized, 'UTF-8'));

        // Test that JSON encoding works after sanitization
        $testArray = ['output' => $sanitized];
        $this->assertIsString(json_encode($testArray, JSON_THROW_ON_ERROR));
    }

    public function test_remove_iip_handles_malformed_utf8()
    {
        // Test with malformed UTF-8 in command output
        $malformedOutput = "Processing image\x80\x81file.webp";
        $cleaned = remove_iip($malformedOutput);
        $this->assertTrue(mb_check_encoding($cleaned, 'UTF-8'));

        // Test that JSON encoding works after cleaning
        $this->assertIsString(json_encode(['output' => $cleaned], JSON_THROW_ON_ERROR));
    }
}
