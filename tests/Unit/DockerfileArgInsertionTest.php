<?php

use App\Jobs\ApplicationDeploymentJob;

/**
 * Test how the deployment job finds the FROM instructions where it inserts ARGs
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

it('ignores FROM text inside heredoc bodies', function () {
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();

    $dockerfile = collect([
        'FROM python:3.13 AS build',
        'RUN <<EOF',
        'from app import create_app',
        'EOF',
        "COPY <<-'SQL' /init.sql",
        "\tSELECT *",
        "\tFROM users;",
        "\tSQL",
        'RUN cat <<<"from here-string"',
        'FROM nginx:alpine',
    ]);

    expect($job->findFromInstructionLines($dockerfile))->toBe([0, 9]);
});

it('returns the last line of a FROM instruction that continues on the next line', function () {
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();

    $dockerfile = collect([
        'FROM --platform=linux/amd64 \\',
        '    # base image',
        '    node:22',
        'RUN echo \\',
        '    FROM not-an-instruction',
        'FROM nginx:alpine',
    ]);

    expect($job->findFromInstructionLines($dockerfile))->toBe([2, 5]);
});

it('uses the escape parser directive for continuation lines', function () {
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();

    $dockerfile = collect([
        '# escape=`',
        'FROM mcr.microsoft.com/windows/servercore:ltsc2022',
        'RUN dir `',
        '    FROM c:\\',
        'FROM nginx:alpine',
    ]);

    expect($job->findFromInstructionLines($dockerfile))->toBe([1, 4]);
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

    expect($job->findFromInstructionLines($dockerfile))->toBe([2, 21]);
});
