<?php

namespace Tests\Unit;

use App\Services\DockerImageParser;
use Tests\TestCase;

class DockerImageParserTest extends TestCase
{
    public function test_parses_regular_image_with_tag()
    {
        $parser = new DockerImageParser;
        $parser->parse('nginx:latest');

        $this->assertEquals('nginx', $parser->getImageName());
        $this->assertEquals('latest', $parser->getTag());
        $this->assertFalse($parser->isImageHash());
        $this->assertEquals('nginx:latest', $parser->toString());
    }

    public function test_parses_image_with_sha256_hash()
    {
        $parser = new DockerImageParser;
        $hash = '59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cf0';
        $parser->parse("ghcr.io/benjaminehowe/rail-disruptions:{$hash}");

        $this->assertEquals('ghcr.io/benjaminehowe/rail-disruptions', $parser->getFullImageNameWithoutTag());
        $this->assertEquals($hash, $parser->getTag());
        $this->assertTrue($parser->isImageHash());
        $this->assertEquals("ghcr.io/benjaminehowe/rail-disruptions@sha256:{$hash}", $parser->toString());
        $this->assertEquals("ghcr.io/benjaminehowe/rail-disruptions@sha256:{$hash}", $parser->getFullImageNameWithHash());
    }

    public function test_parses_registry_image_with_hash()
    {
        $parser = new DockerImageParser;
        $hash = 'abc123def456789abcdef123456789abcdef123456789abcdef123456789abc1';
        $parser->parse("docker.io/library/nginx:{$hash}");

        $this->assertEquals('docker.io/library/nginx', $parser->getFullImageNameWithoutTag());
        $this->assertEquals($hash, $parser->getTag());
        $this->assertTrue($parser->isImageHash());
        $this->assertEquals("docker.io/library/nginx@sha256:{$hash}", $parser->toString());
    }

    public function test_parses_image_without_tag_defaults_to_latest()
    {
        $parser = new DockerImageParser;
        $parser->parse('nginx');

        $this->assertEquals('nginx', $parser->getImageName());
        $this->assertEquals('latest', $parser->getTag());
        $this->assertFalse($parser->isImageHash());
        $this->assertEquals('nginx:latest', $parser->toString());
    }

    public function test_parses_registry_with_port()
    {
        $parser = new DockerImageParser;
        $parser->parse('registry.example.com:5000/myapp:latest');

        $this->assertEquals('registry.example.com:5000/myapp', $parser->getFullImageNameWithoutTag());
        $this->assertEquals('latest', $parser->getTag());
        $this->assertFalse($parser->isImageHash());
    }

    public function test_parses_registry_with_port_and_hash()
    {
        $parser = new DockerImageParser;
        $hash = '1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef';
        $parser->parse("registry.example.com:5000/myapp:{$hash}");

        $this->assertEquals('registry.example.com:5000/myapp', $parser->getFullImageNameWithoutTag());
        $this->assertEquals($hash, $parser->getTag());
        $this->assertTrue($parser->isImageHash());
        $this->assertEquals("registry.example.com:5000/myapp@sha256:{$hash}", $parser->toString());
    }

    public function test_identifies_valid_sha256_hashes()
    {
        $parser = new DockerImageParser;

        // Valid SHA256 hashes
        $validHashes = [
            '59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cf0',
            '1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef',
            'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890',
        ];

        foreach ($validHashes as $hash) {
            $parser->parse("image:{$hash}");
            $this->assertTrue($parser->isImageHash(), "Hash {$hash} should be recognized as valid SHA256");
        }
    }

    public function test_identifies_invalid_sha256_hashes()
    {
        $parser = new DockerImageParser;

        // Invalid SHA256 hashes
        $invalidHashes = [
            'latest',
            'v1.2.3',
            'abc123', // too short
            '59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cf', // too short
            '59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cf00', // too long
            '59e02939b1bf39f16c93138a28727aec520bb916da021180ae502c61626b3cfg0', // invalid char
        ];

        foreach ($invalidHashes as $hash) {
            $parser->parse("image:{$hash}");
            $this->assertFalse($parser->isImageHash(), "Hash {$hash} should not be recognized as valid SHA256");
        }
    }
}
