<?php

use App\Enums\AiProvider;

test('driver maps each provider to a laravel/ai driver name', function () {
    expect(AiProvider::OPENAI->driver())->toBe('openai')
        ->and(AiProvider::ANTHROPIC->driver())->toBe('anthropic')
        ->and(AiProvider::GEMINI->driver())->toBe('gemini')
        ->and(AiProvider::OLLAMA->driver())->toBe('ollama')
        ->and(AiProvider::OPENROUTER->driver())->toBe('openrouter')
        ->and(AiProvider::OPENAI_COMPATIBLE->driver())->toBe('openai-compatible');
});

test('only ollama and openai_compatible require a base url', function () {
    expect(AiProvider::OLLAMA->requiresBaseUrl())->toBeTrue()
        ->and(AiProvider::OPENAI_COMPATIBLE->requiresBaseUrl())->toBeTrue()
        ->and(AiProvider::OPENAI->requiresBaseUrl())->toBeFalse();
});

test('cloud providers expose a default url and a human label', function () {
    expect(AiProvider::ANTHROPIC->defaultUrl())->toBe('https://api.anthropic.com/v1')
        ->and(AiProvider::OPENAI_COMPATIBLE->defaultUrl())->toBeNull()
        ->and(AiProvider::OPENAI->label())->toBe('OpenAI');
});
