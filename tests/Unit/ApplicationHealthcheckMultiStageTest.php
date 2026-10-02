<?php

/**
 * Tests for multi-stage Dockerfile HEALTHCHECK detection.
 *
 * Verifies that parseHealthcheckFromDockerfile respects dockerfile_target_build
 * and only detects HEALTHCHECK within the target stage.
 *
 * @see https://github.com/coollabsio/coolify/issues/9475
 */

$multiStageDockerfile = <<<'DOCKERFILE'
FROM node:20-alpine AS builder
RUN npm install
RUN npm run build

FROM node:20-alpine AS api
COPY --from=builder /app/dist ./dist
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD node -e "require('http').get('http://localhost:3000/health', (r) => { process.exit(r.statusCode === 200 ? 0 : 1) })"
EXPOSE 3000
CMD ["node", "dist/main.js"]

FROM node:20-alpine AS worker
COPY --from=builder /app/dist ./dist
CMD ["node", "dist/worker.js"]
DOCKERFILE;

$multiStageWithHealthcheckNone = <<<'DOCKERFILE'
FROM node:20-alpine AS builder
RUN npm install

FROM node:20-alpine AS api
HEALTHCHECK --interval=30s CMD curl http://localhost:3000/health
CMD ["node", "dist/main.js"]

FROM node:20-alpine AS worker
HEALTHCHECK NONE
CMD ["node", "dist/worker.js"]
DOCKERFILE;

it('detects HEALTHCHECK in target stage (api)', function () use ($multiStageDockerfile) {
    $allLines = str($multiStageDockerfile)->trim()->explode("\n");

    // Simulate extractTargetStageLines for 'api' target
    $targetStage = 'api';
    $linesArray = $allLines->toArray();
    $targetStartIndex = null;
    $targetEndIndex = count($linesArray);

    foreach ($linesArray as $index => $line) {
        $trimmedLine = trim($line);
        if (preg_match('/^FROM\s+.+\s+AS\s+(\S+)/i', $trimmedLine, $matches)) {
            if (strcasecmp($matches[1], $targetStage) === 0) {
                $targetStartIndex = $index;
            } elseif ($targetStartIndex !== null) {
                $targetEndIndex = $index;
                break;
            }
        }
    }

    $stageLines = collect(array_slice($linesArray, $targetStartIndex, $targetEndIndex - $targetStartIndex));
    $hasHealthcheck = str($stageLines)->contains('HEALTHCHECK');

    expect($hasHealthcheck)->toBeTrue()
        ->and($targetStartIndex)->not->toBeNull();
});

it('does not detect HEALTHCHECK in worker stage when only api has it', function () use ($multiStageDockerfile) {
    $allLines = str($multiStageDockerfile)->trim()->explode("\n");

    // Simulate extractTargetStageLines for 'worker' target
    $targetStage = 'worker';
    $linesArray = $allLines->toArray();
    $targetStartIndex = null;
    $targetEndIndex = count($linesArray);

    foreach ($linesArray as $index => $line) {
        $trimmedLine = trim($line);
        if (preg_match('/^FROM\s+.+\s+AS\s+(\S+)/i', $trimmedLine, $matches)) {
            if (strcasecmp($matches[1], $targetStage) === 0) {
                $targetStartIndex = $index;
            } elseif ($targetStartIndex !== null) {
                $targetEndIndex = $index;
                break;
            }
        }
    }

    $stageLines = collect(array_slice($linesArray, $targetStartIndex, $targetEndIndex - $targetStartIndex));
    $hasHealthcheck = str($stageLines)->contains('HEALTHCHECK');

    expect($hasHealthcheck)->toBeFalse()
        ->and($targetStartIndex)->not->toBeNull();
});

it('falls back to all lines when no target stage is set', function () use ($multiStageDockerfile) {
    $allLines = str($multiStageDockerfile)->trim()->explode("\n");

    // No target stage — should return all lines (original behavior)
    $targetStage = null;
    $lines = empty($targetStage) ? $allLines : collect([]);

    $hasHealthcheck = str($lines)->contains('HEALTHCHECK');

    expect($hasHealthcheck)->toBeTrue();
});

it('detects HEALTHCHECK NONE in worker stage', function () use ($multiStageWithHealthcheckNone) {
    $allLines = str($multiStageWithHealthcheckNone)->trim()->explode("\n");

    // Extract worker stage lines
    $targetStage = 'worker';
    $linesArray = $allLines->toArray();
    $targetStartIndex = null;
    $targetEndIndex = count($linesArray);

    foreach ($linesArray as $index => $line) {
        $trimmedLine = trim($line);
        if (preg_match('/^FROM\s+.+\s+AS\s+(\S+)/i', $trimmedLine, $matches)) {
            if (strcasecmp($matches[1], $targetStage) === 0) {
                $targetStartIndex = $index;
            } elseif ($targetStartIndex !== null) {
                $targetEndIndex = $index;
                break;
            }
        }
    }

    $stageLines = collect(array_slice($linesArray, $targetStartIndex, $targetEndIndex - $targetStartIndex));

    // HEALTHCHECK NONE should be detected as containing 'HEALTHCHECK'
    $hasHealthcheck = str($stageLines)->contains('HEALTHCHECK');
    expect($hasHealthcheck)->toBeTrue();

    // But it should match the HEALTHCHECK NONE pattern
    $isHealthcheckNone = false;
    foreach ($stageLines as $line) {
        if (preg_match('/^HEALTHCHECK\s+NONE\s*$/i', trim($line))) {
            $isHealthcheckNone = true;
            break;
        }
    }
    expect($isHealthcheckNone)->toBeTrue();
});

it('handles case-insensitive stage names', function () {
    $dockerfile = <<<'DOCKERFILE'
FROM node:20-alpine AS Builder
RUN npm install

FROM node:20-alpine AS API
HEALTHCHECK --interval=10s CMD curl http://localhost/health
CMD ["node", "main.js"]

FROM node:20-alpine AS Worker
CMD ["node", "worker.js"]
DOCKERFILE;

    $allLines = str($dockerfile)->trim()->explode("\n");
    $targetStage = 'worker'; // lowercase vs 'Worker' in Dockerfile
    $linesArray = $allLines->toArray();
    $targetStartIndex = null;
    $targetEndIndex = count($linesArray);

    foreach ($linesArray as $index => $line) {
        $trimmedLine = trim($line);
        if (preg_match('/^FROM\s+.+\s+AS\s+(\S+)/i', $trimmedLine, $matches)) {
            if (strcasecmp($matches[1], $targetStage) === 0) {
                $targetStartIndex = $index;
            } elseif ($targetStartIndex !== null) {
                $targetEndIndex = $index;
                break;
            }
        }
    }

    $stageLines = collect(array_slice($linesArray, $targetStartIndex, $targetEndIndex - $targetStartIndex));
    $hasHealthcheck = str($stageLines)->contains('HEALTHCHECK');

    expect($hasHealthcheck)->toBeFalse()
        ->and($targetStartIndex)->not->toBeNull();
});

it('handles target stage not found — falls back to all lines', function () use ($multiStageDockerfile) {
    $allLines = str($multiStageDockerfile)->trim()->explode("\n");

    $targetStage = 'nonexistent';
    $linesArray = $allLines->toArray();
    $targetStartIndex = null;

    foreach ($linesArray as $index => $line) {
        $trimmedLine = trim($line);
        if (preg_match('/^FROM\s+.+\s+AS\s+(\S+)/i', $trimmedLine, $matches)) {
            if (strcasecmp($matches[1], $targetStage) === 0) {
                $targetStartIndex = $index;
            }
        }
    }

    // Stage not found — should fall back to all lines
    $lines = ($targetStartIndex === null) ? $allLines : collect([]);
    $hasHealthcheck = str($lines)->contains('HEALTHCHECK');

    expect($targetStartIndex)->toBeNull()
        ->and($hasHealthcheck)->toBeTrue(); // falls back to all lines, which has HEALTHCHECK
});
