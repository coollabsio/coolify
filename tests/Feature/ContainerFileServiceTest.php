<?php

namespace Tests\Unit;

use App\Models\Server;
use App\Services\ContainerFileService;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

class ContainerFileServiceTest extends TestCase
{
    protected ContainerFileService $service;

    protected Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ContainerFileService;
        $this->server = Mockery::mock(Server::class);
        $this->service->setServer($this->server);
    }

    public function test_sanitizes_file_paths_correctly()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('sanitizePath');
        $method->setAccessible(true);

        // Test directory traversal prevention
        $this->assertEquals('/etc', $method->invoke($this->service, '../../../etc'));
        $this->assertEquals('/home/user', $method->invoke($this->service, '/../home/user'));
        $this->assertEquals('/test/path', $method->invoke($this->service, 'test/path'));
        $this->assertEquals('/test/path', $method->invoke($this->service, '//test//path//'));
    }

    public function test_validates_permissions_format()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validatePermissions');
        $method->setAccessible(true);

        // Valid permissions should not throw
        $method->invoke($this->service, '644');
        $method->invoke($this->service, '755');
        $method->invoke($this->service, '0644');

        // Invalid permissions should throw
        $this->expectException(\Exception::class);
        $method->invoke($this->service, '999');
    }

    public function test_validates_file_upload_size()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateFileUpload');
        $method->setAccessible(true);

        // Create a mock file that reports large size
        $file = Mockery::mock(UploadedFile::class);
        $file->shouldReceive('getSize')->andReturn(101 * 1024 * 1024); // 101MB
        $file->shouldReceive('getClientOriginalExtension')->andReturn('txt');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File size exceeds maximum allowed size');

        $method->invoke($this->service, $file);
    }

    public function test_validates_dangerous_file_types()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('validateFileUpload');
        $method->setAccessible(true);

        $file = Mockery::mock(UploadedFile::class);
        $file->shouldReceive('getSize')->andReturn(1024); // 1KB
        $file->shouldReceive('getClientOriginalExtension')->andReturn('exe');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File type not allowed for security reasons');

        $method->invoke($this->service, $file);
    }

    public function test_parses_file_list_output_correctly()
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('parseFileList');
        $method->setAccessible(true);

        $lsOutput = 'total 16
drwxr-xr-x 2 root root 4096 Sep  2 19:45 dir1
-rw-r--r-- 1 user user 1024 Sep  2 19:44 file1.txt
-rwxr-xr-x 1 user user 2048 Sep  2 19:43 script.sh';

        $result = $method->invoke($this->service, $lsOutput, '/test');

        $this->assertCount(3, $result);

        $this->assertEquals('dir1', $result[0]['name']);
        $this->assertEquals('directory', $result[0]['type']);
        $this->assertEquals('drwxr-xr-x', $result[0]['permissions']);

        $this->assertEquals('file1.txt', $result[1]['name']);
        $this->assertEquals('file', $result[1]['type']);
        $this->assertEquals(1024, $result[1]['size']);

        $this->assertEquals('script.sh', $result[2]['name']);
        $this->assertEquals('file', $result[2]['type']);
        $this->assertEquals('-rwxr-xr-x', $result[2]['permissions']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
