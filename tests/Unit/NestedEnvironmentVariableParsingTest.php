<?php

/**
 * Tests for nested environment variable parsing in Docker Compose files.
 *
 * These tests verify that the parser correctly handles nested variable substitution syntax
 * like ${API_URL:-${SERVICE_URL_YOLO}/api} where defaults can contain other variable references.
 */
test('nested variable syntax is parsed correctly', function () {
    // Test the exact scenario from the bug report
    $input = '${API_URL:-${SERVICE_URL_YOLO}/api}';

    $result = extractBalancedBraceContent($input, 0);

    expect($result)->not->toBeNull()
        ->and($result['content'])->toBe('API_URL:-${SERVICE_URL_YOLO}/api');

    $split = splitOnOperatorOutsideNested($result['content']);

    expect($split)->not->toBeNull()
        ->and($split['variable'])->toBe('API_URL')
        ->and($split['operator'])->toBe(':-')
        ->and($split['default'])->toBe('${SERVICE_URL_YOLO}/api');
});

test('replaceVariables correctly extracts nested variable content', function () {
    // Before the fix, this would incorrectly extract only up to the first closing brace
    $result = replaceVariables('${API_URL:-${SERVICE_URL_YOLO}/api}');

    // Should extract the full content, not just "${API_URL:-${SERVICE_URL_YOLO"
    expect($result->value())->toBe('API_URL:-${SERVICE_URL_YOLO}/api')
        ->and($result->value())->not->toBe('API_URL:-${SERVICE_URL_YOLO'); // Not truncated
});

test('nested defaults with path concatenation work', function () {
    $input = '${REDIS_URL:-${SERVICE_URL_REDIS}/db/0}';

    $result = extractBalancedBraceContent($input, 0);
    $split = splitOnOperatorOutsideNested($result['content']);

    expect($split['variable'])->toBe('REDIS_URL')
        ->and($split['default'])->toBe('${SERVICE_URL_REDIS}/db/0');
});

test('deeply nested variables are handled', function () {
    // Three levels of nesting
    $input = '${A:-${B:-${C}}}';

    $result = extractBalancedBraceContent($input, 0);

    expect($result['content'])->toBe('A:-${B:-${C}}');

    $split = splitOnOperatorOutsideNested($result['content']);

    expect($split['variable'])->toBe('A')
        ->and($split['default'])->toBe('${B:-${C}}');
});

test('multiple nested variables in default value', function () {
    // Default value contains multiple variable references
    $input = '${API:-${SERVICE_URL}:${SERVICE_PORT}/api}';

    $result = extractBalancedBraceContent($input, 0);
    $split = splitOnOperatorOutsideNested($result['content']);

    expect($split['variable'])->toBe('API')
        ->and($split['default'])->toBe('${SERVICE_URL}:${SERVICE_PORT}/api');
});

test('nested variables with different operators', function () {
    // Nested variable uses different operator
    $input = '${API_URL:-${SERVICE_URL?error message}/api}';

    $result = extractBalancedBraceContent($input, 0);
    $split = splitOnOperatorOutsideNested($result['content']);

    expect($split['variable'])->toBe('API_URL')
        ->and($split['operator'])->toBe(':-')
        ->and($split['default'])->toBe('${SERVICE_URL?error message}/api');
});

test('backward compatibility with simple variables', function () {
    // Simple variable without nesting should still work
    $input = '${VAR}';

    $result = replaceVariables($input);

    expect($result->value())->toBe('VAR');
});

test('backward compatibility with single-level defaults', function () {
    // Single-level default without nesting
    $input = '${VAR:-default_value}';

    $result = replaceVariables($input);

    expect($result->value())->toBe('VAR:-default_value');

    $split = splitOnOperatorOutsideNested($result->value());

    expect($split['variable'])->toBe('VAR')
        ->and($split['default'])->toBe('default_value');
});

test('backward compatibility with dash operator', function () {
    $input = '${VAR-default}';

    $result = replaceVariables($input);
    $split = splitOnOperatorOutsideNested($result->value());

    expect($split['operator'])->toBe('-');
});

test('backward compatibility with colon question operator', function () {
    $input = '${VAR:?error message}';

    $result = replaceVariables($input);
    $split = splitOnOperatorOutsideNested($result->value());

    expect($split['operator'])->toBe(':?')
        ->and($split['default'])->toBe('error message');
});

test('backward compatibility with question operator', function () {
    $input = '${VAR?error}';

    $result = replaceVariables($input);
    $split = splitOnOperatorOutsideNested($result->value());

    expect($split['operator'])->toBe('?')
        ->and($split['default'])->toBe('error');
});

test('SERVICE_URL magic variables in nested defaults', function () {
    // Real-world scenario: SERVICE_URL_* magic variable used in nested default
    $input = '${DATABASE_URL:-${SERVICE_URL_POSTGRES}/mydb}';

    $result = extractBalancedBraceContent($input, 0);
    $split = splitOnOperatorOutsideNested($result['content']);

    expect($split['variable'])->toBe('DATABASE_URL')
        ->and($split['default'])->toBe('${SERVICE_URL_POSTGRES}/mydb');

    // Extract the nested SERVICE_URL variable
    $nestedResult = extractBalancedBraceContent($split['default'], 0);

    expect($nestedResult['content'])->toBe('SERVICE_URL_POSTGRES');
});

test('SERVICE_FQDN magic variables in nested defaults', function () {
    $input = '${API_HOST:-${SERVICE_FQDN_API}}';

    $result = extractBalancedBraceContent($input, 0);
    $split = splitOnOperatorOutsideNested($result['content']);

    expect($split['default'])->toBe('${SERVICE_FQDN_API}');

    $nestedResult = extractBalancedBraceContent($split['default'], 0);

    expect($nestedResult['content'])->toBe('SERVICE_FQDN_API');
});

test('complex real-world example', function () {
    // Complex real-world scenario from the bug report
    $input = '${API_URL:-${SERVICE_URL_YOLO}/api}';

    // Step 1: Extract outer variable content
    $result = extractBalancedBraceContent($input, 0);
    expect($result['content'])->toBe('API_URL:-${SERVICE_URL_YOLO}/api');

    // Step 2: Split on operator
    $split = splitOnOperatorOutsideNested($result['content']);
    expect($split['variable'])->toBe('API_URL');
    expect($split['operator'])->toBe(':-');
    expect($split['default'])->toBe('${SERVICE_URL_YOLO}/api');

    // Step 3: Extract nested variable
    $nestedResult = extractBalancedBraceContent($split['default'], 0);
    expect($nestedResult['content'])->toBe('SERVICE_URL_YOLO');

    // This verifies that:
    // 1. API_URL should be created with value "${SERVICE_URL_YOLO}/api"
    // 2. SERVICE_URL_YOLO should be recognized and created as magic variable
});

test('empty nested default values', function () {
    $input = '${VAR:-${NESTED:-}}';

    $result = extractBalancedBraceContent($input, 0);
    $split = splitOnOperatorOutsideNested($result['content']);

    expect($split['default'])->toBe('${NESTED:-}');

    $nestedResult = extractBalancedBraceContent($split['default'], 0);
    $nestedSplit = splitOnOperatorOutsideNested($nestedResult['content']);

    expect($nestedSplit['default'])->toBe('');
});

test('nested variables with complex paths', function () {
    $input = '${CONFIG_URL:-${SERVICE_URL_CONFIG}/v2/config.json}';

    $result = extractBalancedBraceContent($input, 0);
    $split = splitOnOperatorOutsideNested($result['content']);

    expect($split['default'])->toBe('${SERVICE_URL_CONFIG}/v2/config.json');
});

test('replaceVariables strips leading dollar sign from bare $VAR format', function () {
    // Bug #8851: When a compose value is $SERVICE_USER_POSTGRES (bare $VAR, no braces),
    // replaceVariables must strip the $ so the parsed name is SERVICE_USER_POSTGRES.
    // Without this, the fallback code path creates a DB entry with key=$SERVICE_USER_POSTGRES.
    expect(replaceVariables('$SERVICE_USER_POSTGRES')->value())->toBe('SERVICE_USER_POSTGRES')
        ->and(replaceVariables('$SERVICE_PASSWORD_POSTGRES')->value())->toBe('SERVICE_PASSWORD_POSTGRES')
        ->and(replaceVariables('$SERVICE_FQDN_APPWRITE')->value())->toBe('SERVICE_FQDN_APPWRITE');
});

test('bare dollar variable in bash-style fallback does not capture trailing brace', function () {
    // Bug #8851: ${_APP_DOMAIN:-$SERVICE_FQDN_APPWRITE} causes the regex to
    // capture "SERVICE_FQDN_APPWRITE}" (with trailing }) because \}? in the regex
    // greedily matches the closing brace of the outer ${...} construct.
    // The fix uses capture group 2 (clean variable name) instead of group 1.
    $value = '${_APP_DOMAIN:-$SERVICE_FQDN_APPWRITE}';

    $regex = '/\$(\{?([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\}?)/';
    preg_match_all($regex, $value, $valueMatches);

    // Group 2 should contain clean variable names without any braces
    expect($valueMatches[2])->toContain('_APP_DOMAIN')
        ->and($valueMatches[2])->toContain('SERVICE_FQDN_APPWRITE');

    // Verify no match in group 2 has trailing }
    foreach ($valueMatches[2] as $match) {
        expect($match)->not->toEndWith('}', "Variable name '{$match}' should not end with }");
    }

    // Group 1 (previously used) would have the bug — SERVICE_FQDN_APPWRITE}
    // This demonstrates why group 2 must be used instead
    expect($valueMatches[1])->toContain('SERVICE_FQDN_APPWRITE}');
});

test('operator precedence with nesting', function () {
    // The first :- at depth 0 should be used, not the one inside nested braces
    $input = '${A:-${B:-default}}';

    $result = extractBalancedBraceContent($input, 0);
    $split = splitOnOperatorOutsideNested($result['content']);

    // Should split on first :- (at depth 0)
    expect($split['variable'])->toBe('A')
        ->and($split['operator'])->toBe(':-')
        ->and($split['default'])->toBe('${B:-default}'); // Not split here
});
