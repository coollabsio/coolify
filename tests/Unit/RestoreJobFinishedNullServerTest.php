<?php

use App\Events\RestoreJobFinished;
use App\Events\S3RestoreJobFinished;
use App\Models\Server;

/**
 * Tests for RestoreJobFinished and S3RestoreJobFinished events to ensure they handle
 * null server scenarios gracefully (when server is deleted during operation).
 */
describe('RestoreJobFinished null server handling', function () {
    afterEach(function () {
        Mockery::close();
    });

    it('handles null server gracefully in RestoreJobFinished event', function () {
        // Mock Server::find to return null (server was deleted)
        $mockServer = Mockery::mock('alias:'.Server::class);
        $mockServer->shouldReceive('find')
            ->with(999)
            ->andReturn(null);

        $data = [
            'scriptPath' => '/tmp/script.sh',
            'tmpPath' => '/tmp/backup.sql',
            'container' => 'test-container',
            'serverId' => 999,
        ];

        // Should not throw an error when server is null
        expect(fn () => new RestoreJobFinished($data))->not->toThrow(\Throwable::class);
    });

    it('handles null server gracefully in S3RestoreJobFinished event', function () {
        // Mock Server::find to return null (server was deleted)
        $mockServer = Mockery::mock('alias:'.Server::class);
        $mockServer->shouldReceive('find')
            ->with(999)
            ->andReturn(null);

        $data = [
            'containerName' => 'helper-container',
            'serverTmpPath' => '/tmp/downloaded.sql',
            'scriptPath' => '/tmp/script.sh',
            'containerTmpPath' => '/tmp/container-file.sql',
            'container' => 'test-container',
            'serverId' => 999,
        ];

        // Should not throw an error when server is null
        expect(fn () => new S3RestoreJobFinished($data))->not->toThrow(\Throwable::class);
    });

    it('handles empty serverId in RestoreJobFinished event', function () {
        $data = [
            'scriptPath' => '/tmp/script.sh',
            'tmpPath' => '/tmp/backup.sql',
            'container' => 'test-container',
            'serverId' => null,
        ];

        // Should not throw an error when serverId is null
        expect(fn () => new RestoreJobFinished($data))->not->toThrow(\Throwable::class);
    });

    it('handles empty serverId in S3RestoreJobFinished event', function () {
        $data = [
            'containerName' => 'helper-container',
            'serverTmpPath' => '/tmp/downloaded.sql',
            'scriptPath' => '/tmp/script.sh',
            'containerTmpPath' => '/tmp/container-file.sql',
            'container' => 'test-container',
            'serverId' => null,
        ];

        // Should not throw an error when serverId is null
        expect(fn () => new S3RestoreJobFinished($data))->not->toThrow(\Throwable::class);
    });

    it('handles missing data gracefully in RestoreJobFinished', function () {
        $data = [];

        // Should not throw an error when data is empty
        expect(fn () => new RestoreJobFinished($data))->not->toThrow(\Throwable::class);
    });

    it('handles missing data gracefully in S3RestoreJobFinished', function () {
        $data = [];

        // Should not throw an error when data is empty
        expect(fn () => new S3RestoreJobFinished($data))->not->toThrow(\Throwable::class);
    });
});
