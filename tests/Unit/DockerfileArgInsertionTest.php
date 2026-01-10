<?php

use App\Jobs\ApplicationDeploymentJob;

/**
 * Test the Dockerfile ARG insertion logic
 * This tests the fix for GitHub issue #7118
 */
it('finds FROM instructions in simple dockerfile', function () {
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();

    $dockerfile = collect([
        'FROM node:16',
        'WORKDIR /app',
        'COPY . .',
    ]);

    $result = $job->findFromInstructionLines($dockerfile);

    expect($result)->toBe([0]);
});

it('finds FROM instructions with comments before', function () {
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();

    $dockerfile = collect([
        '# Build stage',
        '# Another comment',
        'FROM node:16',
        'WORKDIR /app',
    ]);

    $result = $job->findFromInstructionLines($dockerfile);

    expect($result)->toBe([2]);
});

it('finds multiple FROM instructions in multi-stage dockerfile', function () {
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();

    $dockerfile = collect([
        'FROM node:16 AS builder',
        'WORKDIR /app',
        'RUN npm install',
        '',
        'FROM nginx:alpine',
        'COPY --from=builder /app/dist /usr/share/nginx/html',
    ]);

    $result = $job->findFromInstructionLines($dockerfile);

    expect($result)->toBe([0, 4]);
});

it('handles FROM with different cases', function () {
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();

    $dockerfile = collect([
        'from node:16',
        'From nginx:alpine',
        'FROM alpine:latest',
    ]);

    $result = $job->findFromInstructionLines($dockerfile);

    expect($result)->toBe([0, 1, 2]);
});

it('returns empty array when no FROM instructions found', function () {
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();

    $dockerfile = collect([
        '# Just comments',
        'WORKDIR /app',
        'RUN npm install',
    ]);

    $result = $job->findFromInstructionLines($dockerfile);

    expect($result)->toBe([]);
});

it('inserts ARGs after FROM in simple dockerfile', function () {
    $dockerfile = collect([
        'FROM node:16',
        'WORKDIR /app',
        'COPY . .',
    ]);

    $fromLines = [0];
    $argsToInsert = collect(['ARG MY_VAR=value', 'ARG ANOTHER_VAR']);

    foreach (array_reverse($fromLines) as $fromLineIndex) {
        foreach ($argsToInsert->reverse() as $arg) {
            $dockerfile->splice($fromLineIndex + 1, 0, [$arg]);
        }
    }

    expect($dockerfile[0])->toBe('FROM node:16');
    expect($dockerfile[1])->toBe('ARG MY_VAR=value');
    expect($dockerfile[2])->toBe('ARG ANOTHER_VAR');
    expect($dockerfile[3])->toBe('WORKDIR /app');
});

it('inserts ARGs after each FROM in multi-stage dockerfile', function () {
    $dockerfile = collect([
        'FROM node:16 AS builder',
        'WORKDIR /app',
        '',
        'FROM nginx:alpine',
        'COPY --from=builder /app/dist /usr/share/nginx/html',
    ]);

    $fromLines = [0, 3];
    $argsToInsert = collect(['ARG MY_VAR=value']);

    foreach (array_reverse($fromLines) as $fromLineIndex) {
        foreach ($argsToInsert->reverse() as $arg) {
            $dockerfile->splice($fromLineIndex + 1, 0, [$arg]);
        }
    }

    // First stage
    expect($dockerfile[0])->toBe('FROM node:16 AS builder');
    expect($dockerfile[1])->toBe('ARG MY_VAR=value');
    expect($dockerfile[2])->toBe('WORKDIR /app');

    // Second stage (index shifted by +1 due to inserted ARG)
    expect($dockerfile[4])->toBe('FROM nginx:alpine');
    expect($dockerfile[5])->toBe('ARG MY_VAR=value');
});

it('inserts ARGs after FROM when comments precede FROM', function () {
    $dockerfile = collect([
        '# Build stage comment',
        'FROM node:16',
        'WORKDIR /app',
    ]);

    $fromLines = [1];
    $argsToInsert = collect(['ARG MY_VAR=value']);

    foreach (array_reverse($fromLines) as $fromLineIndex) {
        foreach ($argsToInsert->reverse() as $arg) {
            $dockerfile->splice($fromLineIndex + 1, 0, [$arg]);
        }
    }

    expect($dockerfile[0])->toBe('# Build stage comment');
    expect($dockerfile[1])->toBe('FROM node:16');
    expect($dockerfile[2])->toBe('ARG MY_VAR=value');
    expect($dockerfile[3])->toBe('WORKDIR /app');
});

it('handles real-world nuxt multi-stage dockerfile with comments', function () {
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();

    $dockerfile = collect([
        '# Build Stage 1',
        '',
        'FROM node:22-alpine AS build',
        'WORKDIR /app',
        '',
        'RUN corepack enable',
        '',
        '# Copy package.json and your lockfile, here we add pnpm-lock.yaml for illustration',
        'COPY package.json pnpm-lock.yaml .npmrc ./',
        '',
        '# Install dependencies',
        'RUN pnpm i',
        '',
        '# Copy the entire project',
        'COPY . ./',
        '',
        '# Build the project',
        'RUN pnpm run build',
        '',
        '# Build Stage 2',
        '',
        'FROM node:22-alpine',
        'WORKDIR /app',
        '',
        '# Only `.output` folder is needed from the build stage',
        'COPY --from=build /app/.output/ ./',
        '',
        '# Change the port and host',
        'ENV PORT=80',
        'ENV HOST=0.0.0.0',
        '',
        'EXPOSE 80',
        '',
        'CMD ["node", "/app/server/index.mjs"]',
    ]);

    // Find FROM instructions
    $fromLines = $job->findFromInstructionLines($dockerfile);

    expect($fromLines)->toBe([2, 21]);

    // Simulate ARG insertion
    $argsToInsert = collect(['ARG BUILD_VAR=production']);

    foreach (array_reverse($fromLines) as $fromLineIndex) {
        foreach ($argsToInsert->reverse() as $arg) {
            $dockerfile->splice($fromLineIndex + 1, 0, [$arg]);
        }
    }

    // Verify first stage
    expect($dockerfile[2])->toBe('FROM node:22-alpine AS build');
    expect($dockerfile[3])->toBe('ARG BUILD_VAR=production');
    expect($dockerfile[4])->toBe('WORKDIR /app');

    // Verify second stage (index shifted by +1 due to first ARG insertion)
    expect($dockerfile[22])->toBe('FROM node:22-alpine');
    expect($dockerfile[23])->toBe('ARG BUILD_VAR=production');
    expect($dockerfile[24])->toBe('WORKDIR /app');
});
