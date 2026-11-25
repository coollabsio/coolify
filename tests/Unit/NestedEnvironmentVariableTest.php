<?php

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;

test('extractBalancedBraceContent extracts content from simple variable', function () {
    $result = extractBalancedBraceContent('${VAR}', 0);

    assertNotNull($result);
    expect($result['content'])->toBe('VAR')
        ->and($result['start'])->toBe(1)
        ->and($result['end'])->toBe(5);
});

test('extractBalancedBraceContent handles nested braces', function () {
    $result = extractBalancedBraceContent('${API_URL:-${SERVICE_URL_YOLO}/api}', 0);

    assertNotNull($result);
    expect($result['content'])->toBe('API_URL:-${SERVICE_URL_YOLO}/api')
        ->and($result['start'])->toBe(1)
        ->and($result['end'])->toBe(34); // Position of closing }
});

test('extractBalancedBraceContent handles triple nesting', function () {
    $result = extractBalancedBraceContent('${A:-${B:-${C}}}', 0);

    assertNotNull($result);
    expect($result['content'])->toBe('A:-${B:-${C}}');
});

test('extractBalancedBraceContent returns null for unbalanced braces', function () {
    $result = extractBalancedBraceContent('${VAR', 0);

    assertNull($result);
});

test('extractBalancedBraceContent returns null when no braces', function () {
    $result = extractBalancedBraceContent('VAR', 0);

    assertNull($result);
});

test('extractBalancedBraceContent handles startPos parameter', function () {
    $result = extractBalancedBraceContent('foo ${VAR} bar', 4);

    assertNotNull($result);
    expect($result['content'])->toBe('VAR')
        ->and($result['start'])->toBe(5)
        ->and($result['end'])->toBe(9);
});

test('splitOnOperatorOutsideNested splits on :- operator', function () {
    $split = splitOnOperatorOutsideNested('API_URL:-default_value');

    assertNotNull($split);
    expect($split['variable'])->toBe('API_URL')
        ->and($split['operator'])->toBe(':-')
        ->and($split['default'])->toBe('default_value');
});

test('splitOnOperatorOutsideNested handles nested defaults', function () {
    $split = splitOnOperatorOutsideNested('API_URL:-${SERVICE_URL_YOLO}/api');

    assertNotNull($split);
    expect($split['variable'])->toBe('API_URL')
        ->and($split['operator'])->toBe(':-')
        ->and($split['default'])->toBe('${SERVICE_URL_YOLO}/api');
});

test('splitOnOperatorOutsideNested handles dash operator', function () {
    $split = splitOnOperatorOutsideNested('VAR-default');

    assertNotNull($split);
    expect($split['variable'])->toBe('VAR')
        ->and($split['operator'])->toBe('-')
        ->and($split['default'])->toBe('default');
});

test('splitOnOperatorOutsideNested handles colon question operator', function () {
    $split = splitOnOperatorOutsideNested('VAR:?error message');

    assertNotNull($split);
    expect($split['variable'])->toBe('VAR')
        ->and($split['operator'])->toBe(':?')
        ->and($split['default'])->toBe('error message');
});

test('splitOnOperatorOutsideNested handles question operator', function () {
    $split = splitOnOperatorOutsideNested('VAR?error');

    assertNotNull($split);
    expect($split['variable'])->toBe('VAR')
        ->and($split['operator'])->toBe('?')
        ->and($split['default'])->toBe('error');
});

test('splitOnOperatorOutsideNested returns null for simple variable', function () {
    $split = splitOnOperatorOutsideNested('SIMPLE_VAR');

    assertNull($split);
});

test('splitOnOperatorOutsideNested ignores operators inside nested braces', function () {
    $split = splitOnOperatorOutsideNested('A:-${B:-default}');

    assertNotNull($split);
    // Should split on first :- (outside nested braces), not the one inside ${B:-default}
    expect($split['variable'])->toBe('A')
        ->and($split['operator'])->toBe(':-')
        ->and($split['default'])->toBe('${B:-default}');
});

test('replaceVariables handles simple variable', function () {
    $result = replaceVariables('${VAR}');

    expect($result->value())->toBe('VAR');
});

test('replaceVariables handles nested expressions', function () {
    $result = replaceVariables('${API_URL:-${SERVICE_URL_YOLO}/api}');

    expect($result->value())->toBe('API_URL:-${SERVICE_URL_YOLO}/api');
});

test('replaceVariables handles variable with default', function () {
    $result = replaceVariables('${API_URL:-http://localhost}');

    expect($result->value())->toBe('API_URL:-http://localhost');
});

test('replaceVariables returns unchanged for non-variable string', function () {
    $result = replaceVariables('not_a_variable');

    expect($result->value())->toBe('not_a_variable');
});

test('replaceVariables handles triple nesting', function () {
    $result = replaceVariables('${A:-${B:-${C}}}');

    expect($result->value())->toBe('A:-${B:-${C}}');
});

test('replaceVariables fallback works for malformed input', function () {
    // When braces are unbalanced, it falls back to old behavior
    $result = replaceVariables('${VAR');

    // Old behavior would extract everything before first }
    // But since there's no }, it will extract 'VAR' (removing ${)
    expect($result->value())->toContain('VAR');
});

test('extractBalancedBraceContent handles complex nested expression', function () {
    $result = extractBalancedBraceContent('${API:-${SERVICE_URL}/api/v${VERSION:-1}}', 0);

    assertNotNull($result);
    expect($result['content'])->toBe('API:-${SERVICE_URL}/api/v${VERSION:-1}');
});

test('splitOnOperatorOutsideNested handles complex nested expression', function () {
    $split = splitOnOperatorOutsideNested('API:-${SERVICE_URL}/api/v${VERSION:-1}');

    assertNotNull($split);
    expect($split['variable'])->toBe('API')
        ->and($split['operator'])->toBe(':-')
        ->and($split['default'])->toBe('${SERVICE_URL}/api/v${VERSION:-1}');
});

test('extractBalancedBraceContent finds second variable in string', function () {
    $str = '${VAR1} and ${VAR2}';

    // First variable
    $result1 = extractBalancedBraceContent($str, 0);
    assertNotNull($result1);
    expect($result1['content'])->toBe('VAR1');

    // Second variable
    $result2 = extractBalancedBraceContent($str, $result1['end'] + 1);
    assertNotNull($result2);
    expect($result2['content'])->toBe('VAR2');
});

test('replaceVariables handles empty default value', function () {
    $result = replaceVariables('${VAR:-}');

    expect($result->value())->toBe('VAR:-');
});

test('splitOnOperatorOutsideNested handles empty default value', function () {
    $split = splitOnOperatorOutsideNested('VAR:-');

    assertNotNull($split);
    expect($split['variable'])->toBe('VAR')
        ->and($split['operator'])->toBe(':-')
        ->and($split['default'])->toBe('');
});

test('replaceVariables handles brace format without dollar sign', function () {
    // This format is used by the regex capture group in magic variable detection
    $result = replaceVariables('{SERVICE_URL_YOLO}');
    expect($result->value())->toBe('SERVICE_URL_YOLO');
});

test('replaceVariables handles truncated brace format', function () {
    // When regex captures {VAR from a larger expression, no closing brace
    $result = replaceVariables('{API_URL');
    expect($result->value())->toBe('API_URL');
});
