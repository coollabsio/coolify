<?php

namespace Tests\Unit\Services;

use App\Services\DockerImageParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DockerImageParserTest extends TestCase
{
    private DockerImageParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new DockerImageParser;
    }

    #[Test]
    public function it_parses_simple_image_name()
    {
        $this->parser->parse('nginx');

        $this->assertEquals('', $this->parser->getRegistryUrl());
        $this->assertEquals('nginx', $this->parser->getImageName());
        $this->assertEquals('latest', $this->parser->getTag());
    }

    #[Test]
    public function it_parses_image_with_tag()
    {
        $this->parser->parse('nginx:1.19');

        $this->assertEquals('', $this->parser->getRegistryUrl());
        $this->assertEquals('nginx', $this->parser->getImageName());
        $this->assertEquals('1.19', $this->parser->getTag());
    }

    #[Test]
    public function it_parses_image_with_organization()
    {
        $this->parser->parse('coollabs/coolify:latest');

        $this->assertEquals('', $this->parser->getRegistryUrl());
        $this->assertEquals('coollabs/coolify', $this->parser->getImageName());
        $this->assertEquals('latest', $this->parser->getTag());
    }

    #[Test]
    public function it_parses_image_with_registry_url()
    {
        $this->parser->parse('ghcr.io/coollabs/coolify:v4');

        $this->assertEquals('ghcr.io', $this->parser->getRegistryUrl());
        $this->assertEquals('coollabs/coolify', $this->parser->getImageName());
        $this->assertEquals('v4', $this->parser->getTag());
    }

    #[Test]
    public function it_parses_image_with_port_in_registry()
    {
        $this->parser->parse('localhost:5000/my-app:dev');

        $this->assertEquals('localhost:5000', $this->parser->getRegistryUrl());
        $this->assertEquals('my-app', $this->parser->getImageName());
        $this->assertEquals('dev', $this->parser->getTag());
    }

    #[Test]
    public function it_parses_image_without_tag()
    {
        $this->parser->parse('ghcr.io/coollabs/coolify');

        $this->assertEquals('ghcr.io', $this->parser->getRegistryUrl());
        $this->assertEquals('coollabs/coolify', $this->parser->getImageName());
        $this->assertEquals('latest', $this->parser->getTag());
    }

    #[Test]
    public function it_converts_back_to_string()
    {
        $originalString = 'ghcr.io/coollabs/coolify:v4';
        $this->parser->parse($originalString);

        $this->assertEquals($originalString, $this->parser->toString());
    }

    #[Test]
    public function it_converts_to_string_with_default_tag()
    {
        $this->parser->parse('nginx');
        $this->assertEquals('nginx:latest', $this->parser->toString());
    }
}
