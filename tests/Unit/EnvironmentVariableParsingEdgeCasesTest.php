<?php

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;

// ─── Malformed Variables ───────────────────────────────────────────────────────

test('extractBalancedBraceContent handles empty variable name', function () {
    $result = extractBalancedBraceContent('${}', 0);

    assertNotNull($result);
    expect($result['content'])->toBe('');
});

test('splitOnOperatorOutsideNested handles empty variable name with default', function () {
    $split = splitOnOperatorOutsideNested(':-default');

    assertNotNull($split);
    expect($split['variable'])->toBe('')
        ->and($split['operator'])->toBe(':-')
        ->and($split['default'])->toBe('default');
});

test('extractBalancedBraceContent handles double opening brace', function () {
    $result = extractBalancedBraceContent('${{VAR}}', 0);

    assertNotNull($result);
    expect($result['content'])->toBe('{VAR}');
});

test('extractBalancedBraceContent returns null for empty string', function () {
    $result = extractBalancedBraceContent('', 0);

    assertNull($result);
});

test('extractBalancedBraceContent returns null for just dollar sign', function () {
    $result = extractBalancedBraceContent('$', 0);

    assertNull($result);
});

test('extractBalancedBraceContent returns null for just opening brace', function () {
    $result = extractBalancedBraceContent('{', 0);

    assertNull($result);
});

test('extractBalancedBraceContent returns null for just closing brace', function () {
    $result = extractBalancedBraceContent('}', 0);

    assertNull($result);
});

test('extractBalancedBraceContent handles extra closing brace', function () {
    $result = extractBalancedBraceContent('${VAR}}', 0);

    assertNotNull($result);
    expect($result['content'])->toBe('VAR');
});

test('extractBalancedBraceContent returns null for unclosed with no content', function () {
    $result = extractBalancedBraceContent('${', 0);

    assertNull($result);
});

test('extractBalancedBraceContent returns null for deeply unclosed nested braces', function () {
    $result = extractBalancedBraceContent('${A:-${B:-${C}', 0);

    assertNull($result);
});

test('replaceVariables handles empty braces gracefully', function () {
    $result = replaceVariables('${}');

    expect($result->value())->toBe('');
});

test('replaceVariables handles double braces gracefully', function () {
    $result = replaceVariables('${{VAR}}');

    expect($result->value())->toBe('{VAR}');
});

// ─── Edge Cases with Braces and Special Characters ─────────────────────────────

test('extractBalancedBraceContent finds consecutive variables', function () {
    $str = '${A}${B}';

    $first = extractBalancedBraceContent($str, 0);
    assertNotNull($first);
    expect($first['content'])->toBe('A');

    $second = extractBalancedBraceContent($str, $first['end'] + 1);
    assertNotNull($second);
    expect($second['content'])->toBe('B');
});

test('splitOnOperatorOutsideNested handles URL with port in default', function () {
    $split = splitOnOperatorOutsideNested('URL:-http://host:8080/path');

    assertNotNull($split);
    expect($split['variable'])->toBe('URL')
        ->and($split['operator'])->toBe(':-')
        ->and($split['default'])->toBe('http://host:8080/path');
});

test('splitOnOperatorOutsideNested handles equals sign in default', function () {
    $split = splitOnOperatorOutsideNested('VAR:-key=value&foo=bar');

    assertNotNull($split);
    expect($split['variable'])->toBe('VAR')
        ->and($split['operator'])->toBe(':-')
        ->and($split['default'])->toBe('key=value&foo=bar');
});

test('splitOnOperatorOutsideNested handles dashes in default value', function () {
    $split = splitOnOperatorOutsideNested('A:-value-with-dashes');

    assertNotNull($split);
    expect($split['variable'])->toBe('A')
        ->and($split['operator'])->toBe(':-')
        ->and($split['default'])->toBe('value-with-dashes');
});

test('splitOnOperatorOutsideNested handles question mark in default value', function () {
    $split = splitOnOperatorOutsideNested('A:-what?');

    assertNotNull($split);
    expect($split['variable'])->toBe('A')
        ->and($split['operator'])->toBe(':-')
        ->and($split['default'])->toBe('what?');
});

test('extractBalancedBraceContent handles variable with digits', function () {
    $result = extractBalancedBraceContent('${VAR123}', 0);

    assertNotNull($result);
    expect($result['content'])->toBe('VAR123');
});

test('extractBalancedBraceContent handles long variable name', function () {
    $longName = str_repeat('A', 200);
    $result = extractBalancedBraceContent('${'.$longName.'}', 0);

    assertNotNull($result);
    expect($result['content'])->toBe($longName);
});

test('splitOnOperatorOutsideNested returns null for empty string', function () {
    $split = splitOnOperatorOutsideNested('');

    assertNull($split);
});

test('splitOnOperatorOutsideNested handles variable name with underscores', function () {
    $split = splitOnOperatorOutsideNested('_MY_VAR_:-default');

    assertNotNull($split);
    expect($split['variable'])->toBe('_MY_VAR_')
        ->and($split['default'])->toBe('default');
});

test('extractBalancedBraceContent with startPos beyond string length', function () {
    $result = extractBalancedBraceContent('${VAR}', 100);

    assertNull($result);
});

test('extractBalancedBraceContent handles brace in middle of text', function () {
    $result = extractBalancedBraceContent('prefix ${VAR} suffix', 0);

    assertNotNull($result);
    expect($result['content'])->toBe('VAR');
});

// ─── Deeply Nested Defaults ────────────────────────────────────────────────────

test('extractBalancedBraceContent handles four levels of nesting', function () {
    $input = '${A:-${B:-${C:-${D}}}}';

    $result = extractBalancedBraceContent($input, 0);

    assertNotNull($result);
    expect($result['content'])->toBe('A:-${B:-${C:-${D}}}');
});

test('splitOnOperatorOutsideNested handles four levels of nesting', function () {
    $content = 'A:-${B:-${C:-${D}}}';
    $split = splitOnOperatorOutsideNested($content);

    assertNotNull($split);
    expect($split['variable'])->toBe('A')
        ->and($split['operator'])->toBe(':-')
        ->and($split['default'])->toBe('${B:-${C:-${D}}}');

    // Verify second level
    $nested = extractBalancedBraceContent($split['default'], 0);
    assertNotNull($nested);
    $split2 = splitOnOperatorOutsideNested($nested['content']);
    assertNotNull($split2);
    expect($split2['variable'])->toBe('B')
        ->and($split2['default'])->toBe('${C:-${D}}');
});

test('multiple variables at same depth in default', function () {
    $input = '${A:-${B}/${C}/${D}}';

    $result = extractBalancedBraceContent($input, 0);
    assertNotNull($result);

    $split = splitOnOperatorOutsideNested($result['content']);
    assertNotNull($split);
    expect($split['default'])->toBe('${B}/${C}/${D}');

    // Verify all three nested variables can be found
    $default = $split['default'];
    $vars = [];
    $pos = 0;
    while (($nested = extractBalancedBraceContent($default, $pos)) !== null) {
        $vars[] = $nested['content'];
        $pos = $nested['end'] + 1;
    }

    expect($vars)->toBe(['B', 'C', 'D']);
});

test('nested with mixed operators', function () {
    $input = '${A:-${B:?required}}';

    $result = extractBalancedBraceContent($input, 0);
    $split = splitOnOperatorOutsideNested($result['content']);

    expect($split['variable'])->toBe('A')
        ->and($split['operator'])->toBe(':-')
        ->and($split['default'])->toBe('${B:?required}');

    // Inner variable uses :? operator
    $nested = extractBalancedBraceContent($split['default'], 0);
    $innerSplit = splitOnOperatorOutsideNested($nested['content']);

    expect($innerSplit['variable'])->toBe('B')
        ->and($innerSplit['operator'])->toBe(':?')
        ->and($innerSplit['default'])->toBe('required');
});

test('nested variable without default as default', function () {
    $input = '${A:-${B}}';

    $result = extractBalancedBraceContent($input, 0);
    $split = splitOnOperatorOutsideNested($result['content']);

    expect($split['default'])->toBe('${B}');

    $nested = extractBalancedBraceContent($split['default'], 0);
    $innerSplit = splitOnOperatorOutsideNested($nested['content']);

    assertNull($innerSplit);
    expect($nested['content'])->toBe('B');
});

// ─── Backwards Compatibility ───────────────────────────────────────────────────

test('replaceVariables with brace format without dollar sign', function () {
    $result = replaceVariables('{MY_VAR}');

    expect($result->value())->toBe('MY_VAR');
});

test('replaceVariables with truncated brace format', function () {
    $result = replaceVariables('{MY_VAR');

    expect($result->value())->toBe('MY_VAR');
});

test('replaceVariables with plain string returns unchanged', function () {
    $result = replaceVariables('plain_value');

    expect($result->value())->toBe('plain_value');
});

test('replaceVariables preserves full content for variable with default', function () {
    $result = replaceVariables('${DB_HOST:-localhost}');

    expect($result->value())->toBe('DB_HOST:-localhost');
});

test('replaceVariables preserves nested content for variable with nested default', function () {
    $result = replaceVariables('${DB_URL:-${SERVICE_URL_PG}/db}');

    expect($result->value())->toBe('DB_URL:-${SERVICE_URL_PG}/db');
});

test('replaceVariables with brace format containing default falls back gracefully', function () {
    $result = replaceVariables('{VAR:-default}');

    expect($result->value())->toBe('VAR:-default');
});

test('splitOnOperatorOutsideNested colon-dash takes precedence over bare dash', function () {
    $split = splitOnOperatorOutsideNested('VAR:-val-ue');

    assertNotNull($split);
    expect($split['operator'])->toBe(':-')
        ->and($split['variable'])->toBe('VAR')
        ->and($split['default'])->toBe('val-ue');
});

test('splitOnOperatorOutsideNested colon-question takes precedence over bare question', function () {
    $split = splitOnOperatorOutsideNested('VAR:?error?');

    assertNotNull($split);
    expect($split['operator'])->toBe(':?')
        ->and($split['variable'])->toBe('VAR')
        ->and($split['default'])->toBe('error?');
});

test('full round trip: extract, split, and resolve nested variables', function () {
    $input = '${APP_URL:-${SERVICE_URL_APP}/v${API_VERSION:-2}/health}';

    // Step 1: Extract outer content
    $result = extractBalancedBraceContent($input, 0);
    assertNotNull($result);
    expect($result['content'])->toBe('APP_URL:-${SERVICE_URL_APP}/v${API_VERSION:-2}/health');

    // Step 2: Split on outer operator
    $split = splitOnOperatorOutsideNested($result['content']);
    assertNotNull($split);
    expect($split['variable'])->toBe('APP_URL')
        ->and($split['default'])->toBe('${SERVICE_URL_APP}/v${API_VERSION:-2}/health');

    // Step 3: Find all nested variables in default
    $default = $split['default'];
    $nestedVars = [];
    $pos = 0;
    while (($nested = extractBalancedBraceContent($default, $pos)) !== null) {
        $innerSplit = splitOnOperatorOutsideNested($nested['content']);
        $nestedVars[] = [
            'name' => $innerSplit !== null ? $innerSplit['variable'] : $nested['content'],
            'default' => $innerSplit !== null ? $innerSplit['default'] : null,
        ];
        $pos = $nested['end'] + 1;
    }

    expect($nestedVars)->toHaveCount(2)
        ->and($nestedVars[0]['name'])->toBe('SERVICE_URL_APP')
        ->and($nestedVars[0]['default'])->toBeNull()
        ->and($nestedVars[1]['name'])->toBe('API_VERSION')
        ->and($nestedVars[1]['default'])->toBe('2');
});
