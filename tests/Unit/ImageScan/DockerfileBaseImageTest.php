<?php

use App\Services\ImageScan\DockerfileBaseImage;

it('reads the base image of the final stage', function (string $dockerfile, ?string $expected) {
    expect(DockerfileBaseImage::finalStage($dockerfile))->toBe($expected);
})->with([
    'single stage' => ["FROM node:20.11-alpine\nWORKDIR /app\nCOPY . .\nCMD [\"node\", \"index.js\"]\n", 'node:20.11-alpine'],
    'multi-stage, last stage is an image' => ["FROM node:20 AS build\nRUN npm ci\nFROM nginx:1.27-alpine\nCOPY --from=build /app/dist /usr/share/nginx/html\n", 'nginx:1.27-alpine'],
    'multi-stage, alias chain' => ["FROM node:20 AS base\nFROM base AS build\nRUN npm ci\nFROM build\nCMD [\"node\"]\n", 'node:20'],
    'alias names compare without case' => ["FROM node:20 AS Build\nFROM build\n", 'node:20'],
    'platform flag and alias' => ["FROM --platform=\$BUILDPLATFORM golang:1.23 AS b\nRUN go build\nFROM alpine:3.20\nCOPY --from=b /out /app\n", 'alpine:3.20'],
    'platform flag on the final stage' => ["FROM --platform=linux/amd64 alpine:3.20\n", 'alpine:3.20'],
    'alias of a stage with a platform flag' => ["FROM --platform=\$BUILDPLATFORM golang:1.23 AS b\nFROM b\n", 'golang:1.23'],
    'ARG in FROM' => ["ARG BASE=node:20\nFROM \${BASE}\n", null],
    'ARG without braces' => ["ARG VERSION=20\nFROM node:\$VERSION\n", null],
    'ARG reached through an alias' => ["ARG BASE=node:20\nFROM \$BASE AS build\nFROM build\n", null],
    'comments, lowercase from, and line continuations' => ["# syntax=docker/dockerfile:1\n# FROM ubuntu:22.04\nfrom node:20 \\\n  as build\nRUN echo \\\n  FROM debian:12\n  # FROM fedora:40\nfrom \\\n  build\n", 'node:20'],
    'heredoc body is not an instruction' => ["FROM alpine:3.20\nRUN <<EOF\nFROM debian:12\nEOF\n", 'alpine:3.20'],
    'scratch' => ["FROM golang:1.23 AS build\nFROM scratch\nCOPY --from=build /app /app\n", null],
    'scratch through an alias' => ["FROM scratch AS base\nFROM base\n", null],
    'invalid reference' => ["FROM Node:20!\n", null],
    'digest reference' => ['FROM node@sha256:'.str_repeat('a', 64)."\n", 'node@sha256:'.str_repeat('a', 64)],
    'registry with a port' => ["FROM localhost:5000/team/base:1.2 AS b\nFROM b\n", 'localhost:5000/team/base:1.2'],
    'no FROM' => ["RUN echo hi\n", null],
    'empty' => ['', null],
    'CRLF line endings' => ["FROM node:20 AS build\r\nFROM build\r\n", 'node:20'],
]);

it('reads the base image of the build target stage', function () {
    $dockerfile = "FROM node:20 AS build\nFROM build AS dev\nFROM nginx:1.27 AS prod\n";

    expect(DockerfileBaseImage::finalStage($dockerfile, 'dev'))->toBe('node:20')
        ->and(DockerfileBaseImage::finalStage($dockerfile, 'PROD'))->toBe('nginx:1.27')
        ->and(DockerfileBaseImage::finalStage($dockerfile, 'missing'))->toBeNull();
});
