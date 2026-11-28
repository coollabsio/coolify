<?php

use App\Services\DeployTriggers\EvaluationSlots;
use Illuminate\Redis\Limiters\ConcurrencyLimiterBuilder;
use Illuminate\Support\Facades\Redis;

it('holds an evaluation slot for as long as the evaluation job may run', function () {
    $builder = Mockery::mock(ConcurrencyLimiterBuilder::class);
    $builder->shouldReceive('limit')->with(EvaluationSlots::LIMIT)->andReturnSelf();
    $builder->shouldReceive('releaseAfter')->once()->with(900)->andReturnSelf();
    $builder->shouldReceive('block')->with(0)->andReturnSelf();
    $builder->shouldReceive('then')->andReturnUsing(fn (Closure $callback) => $callback());
    Redis::shouldReceive('funnel')->with('push-evaluation')->andReturn($builder);
    $ran = false;

    (new EvaluationSlots)->run(function () use (&$ran) {
        $ran = true;
    }, fn () => null);

    expect($ran)->toBeTrue();
});
