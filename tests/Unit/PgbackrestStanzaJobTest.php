<?php

use App\Jobs\PgbackrestStanzaJob;
use App\Models\StandalonePostgresql;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

beforeEach(function () {
    $this->database = Mockery::mock(StandalonePostgresql::class)->makePartial();
    $this->database->shouldReceive('isPgbackrestEnabled')->andReturn(true);
    $this->database->shouldReceive('getPgbackrestContainerName')->andReturn('test-uuid-pgbackrest');
    $this->database->shouldReceive('getPgbackrestStanzaName')->andReturn('db-test-uuid');
});

afterEach(function () {
    Mockery::close();
});

it('implements required queue interfaces', function () {
    $job = new PgbackrestStanzaJob($this->database);

    $interfaces = class_implements($job);
    expect($interfaces)->toContain(ShouldQueue::class);
    expect($interfaces)->toContain(ShouldBeEncrypted::class);
});

it('has correct job configuration', function () {
    $job = new PgbackrestStanzaJob($this->database);

    expect($job->tries)->toBe(3);
    expect($job->timeout)->toBe(300);
    expect($job->backoff)->toBe([30, 60, 120]);
});

it('queues on high priority queue', function () {
    $job = new PgbackrestStanzaJob($this->database);

    expect($job->queue)->toBe('high');
});

it('defaults to create action', function () {
    $job = new PgbackrestStanzaJob($this->database);

    expect($job->action)->toBe('create');
});

it('accepts upgrade action', function () {
    $job = new PgbackrestStanzaJob($this->database, 'upgrade');

    expect($job->action)->toBe('upgrade');
});

it('accepts check action', function () {
    $job = new PgbackrestStanzaJob($this->database, 'check');

    expect($job->action)->toBe('check');
});

it('stores database reference', function () {
    $job = new PgbackrestStanzaJob($this->database);

    expect($job->database)->toBe($this->database);
});
