<?php

namespace App\I18n;

/**
 * Overlay translator for HTML text nodes and selected attributes.
 */
class UiTranslator
{
    /** @var list<array{0: string, 1: string}> */
    private array $patterns = [
        ['~^(\d+) hour(s)? ago$~u', '$1 saat önce'],
        ['~^(\d+) minute(s)? ago$~u', '$1 dakika önce'],
        ['~^(\d+) second(s)? ago$~u', '$1 saniye önce'],
        ['~^(\d+) day(s)? ago$~u', '$1 gün önce'],
        ['~^(\d+) month(s)? ago$~u', '$1 ay önce'],
        ['~^just now$~u', 'az önce'],
        ['~^(\d+) env(s)?$~u', '$1 ortam'],
        ['~^(\d+) resource(s)?$~u', '$1 kaynak'],
        ['~^(\d+) match(es)?$~u', '$1 eşleşme'],
        ['~^(\d+) app(s)?$~u', '$1 uygulama'],
        ['~^(\d+) server(s)?$~u', '$1 sunucu'],
        ['~^(\d+) project(s)?$~u', '$1 proje'],
        ['~^(\d+)[–-](\d+) of (\d+)$~u', '$1–$2 / $3'],
        ['~^(\d+) of (\d+)$~u', '$1 / $2'],
    ];

    public function __construct(private array $dict) {}

    public function translate(string $html): string
    {
        $protected = [];
        $out = preg_replace_callback(
            '/<(script|style|pre|code|textarea)\b[^>]*>.*?<\/\1>|<!--.*?-->/is',
            static function (array $m) use (&$protected): string {
                $token = "\x18I18N" . count($protected) . "\x19";
                $protected[$token] = $m[0];

                return $token;
            },
            $html
        );
        if (! is_string($out)) {
            return $html;
        }

        $out = preg_replace_callback(
            '/>([^<>]+)</',
            fn (array $m): string => '>' . $this->translateChunk($m[1]) . '<',
            $out
        );
        if (! is_string($out)) {
            return $html;
        }

        $out = preg_replace_callback(
            '/(placeholder|title|aria-label|alt|wire:confirm)="([^"]*)"/',
            fn (array $m): string => $m[1] . '="' . $this->translateChunk($m[2]) . '"',
            $out
        );
        if (! is_string($out)) {
            return $html;
        }

        if ($protected !== []) {
            $out = strtr($out, $protected);
        }

        return $out;
    }

    public function translateLivewireJson(string $json): string
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! isset($data['components'])) {
            return $json;
        }
        if (! is_array($data['components'])) {
            return $json;
        }

        foreach ($data['components'] as $i => $component) {
            if (! is_array($component)) {
                continue;
            }
            $html = $component['effects']['html'] ?? null;
            if (is_string($html)) {
                $data['components'][$i]['effects']['html'] = $this->translate($html);
            }
        }

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            return $json;
        }

        return $encoded;
    }

    private function translateChunk(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '' || strlen($trimmed) <= 1) {
            return $raw;
        }

        $lead = substr($raw, 0, strlen($raw) - strlen(ltrim($raw)));
        $trail = substr($raw, strlen(rtrim($raw)));

        if (isset($this->dict[$trimmed])) {
            return $lead . $this->dict[$trimmed] . $trail;
        }

        $decoded = html_entity_decode($trimmed, ENT_QUOTES, 'UTF-8');
        if (isset($this->dict[$decoded])) {
            return $lead . htmlspecialchars($this->dict[$decoded], ENT_QUOTES, 'UTF-8') . $trail;
        }

        foreach ($this->patterns as [$regex, $replacement]) {
            $replaced = preg_replace($regex, $replacement, $trimmed, 1);
            if (is_string($replaced) && $replaced !== $trimmed) {
                return $lead . $replaced . $trail;
            }
        }

        return $raw;
    }
}
