<?php

use App\Services\DeployTriggers\Resolution\DockerfileSources;

it('collects copy and add sources and bind mounts', function () {
    $sources = DockerfileSources::parse(<<<'DOCKERFILE'
        # syntax=docker/dockerfile:1
        FROM node:22 AS deps
        WORKDIR /app
        COPY package.json pnpm-lock.yaml ./
        COPY --chown=node:node apps/web ./apps/web
        COPY ["packages/ui", "./packages/ui"]
        ADD config/*.json /etc/app/
        RUN --mount=type=bind,source=scripts/build.sh,target=/b.sh sh /b.sh
        COPY --from=deps /app/node_modules ./node_modules
        ADD https://example.com/file.tar.gz /tmp/
        COPY packages/shared \
             ./packages/shared
        DOCKERFILE);

    expect($sources)->toBe(['package.json', 'pnpm-lock.yaml', 'apps/web', 'packages/ui', 'config/*.json', 'scripts/build.sh', 'packages/shared']);
});

it('gives up on sources it cannot bound', function (string $dockerfile) {
    expect(DockerfileSources::parse($dockerfile))->toBeNull();
})->with([
    'whole context' => ["FROM a\nCOPY . ."],
    'dot slash' => ["FROM a\nCOPY ./ /app"],
    'root glob star' => ["FROM a\nCOPY * /app/"],
    'variable' => ["FROM a\nARG DIR\nCOPY \${DIR} /app"],
    'heredoc' => ["FROM a\nCOPY <<EOF /x\nhi\nEOF"],
    'parents flag' => ["FROM a\nCOPY --parents apps/*/package.json /app/"],
    'exclude flag' => ["FROM a\nCOPY --exclude=*.md docs /app"],
    'onbuild' => ["FROM a\nONBUILD COPY . /app"],
    'bind mount without source' => ["FROM a\nRUN --mount=type=bind,target=/src make"],
    'escape directive' => ["# escape=`\nFROM a\nCOPY a b"],
    'custom frontend' => ["# syntax=example.com/frontend:1\nFROM a\nCOPY a b"],
    'named context' => ["FROM a\nCOPY --from=shared /x /y"],
    'escaping the context' => ["FROM a\nCOPY ../shared /app"],
]);

it('treats copies from a stage or an image as no source', function () {
    expect(DockerfileSources::parse("FROM node AS build\nCOPY src /src\nFROM nginx\nCOPY --from=build /src /usr/share/nginx/html\nCOPY --from=nginx:alpine /etc/nginx /etc/nginx"))
        ->toBe(['src']);
});
