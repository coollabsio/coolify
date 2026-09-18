<?php

namespace App\Enums;

enum AiProvider: string
{
    case OPENAI = 'openai';
    case ANTHROPIC = 'anthropic';
    case GEMINI = 'gemini';
    case OLLAMA = 'ollama';
    case OPENROUTER = 'openrouter';
    case OPENAI_COMPATIBLE = 'openai_compatible';

    public function driver(): string
    {
        return match ($this) {
            self::OPENAI_COMPATIBLE => 'openai-compatible',
            default => $this->value,
        };
    }

    public function defaultUrl(): ?string
    {
        return match ($this) {
            self::OPENAI => 'https://api.openai.com/v1',
            self::ANTHROPIC => 'https://api.anthropic.com/v1',
            self::GEMINI => 'https://generativelanguage.googleapis.com/v1beta',
            self::OPENROUTER => 'https://openrouter.ai/api/v1',
            self::OLLAMA => 'http://localhost:11434',
            self::OPENAI_COMPATIBLE => null,
        };
    }

    public function requiresBaseUrl(): bool
    {
        return in_array($this, [self::OLLAMA, self::OPENAI_COMPATIBLE], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::OPENAI => 'OpenAI',
            self::ANTHROPIC => 'Anthropic',
            self::GEMINI => 'Google Gemini',
            self::OLLAMA => 'Ollama',
            self::OPENROUTER => 'OpenRouter',
            self::OPENAI_COMPATIBLE => 'OpenAI-compatible',
        };
    }
}
