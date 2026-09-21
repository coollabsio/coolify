<?php

namespace App\Http\Middleware;

use App\I18n\UiTranslator;
use Closure;
use Illuminate\Http\Request;

class TranslateUi
{
    private static ?array $dict = null;

    private static bool $loaded = false;

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $enabled = env('COOLIFY_TR_UI', true);
        if ($enabled === false || $enabled === 0 || $enabled === '0' || strtolower((string) $enabled) === 'false') {
            return $response;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        $isHtml = str_contains($contentType, 'text/html');
        $isLivewireJson = str_contains($contentType, 'application/json')
            && $request->is('livewire/*');
        if (! $isHtml && ! $isLivewireJson) {
            return $response;
        }

        $dict = self::dictionary();
        if ($dict === null) {
            return $response;
        }

        try {
            $translator = new UiTranslator($dict);
            $content = (string) $response->getContent();
            if ($isLivewireJson) {
                $response->setContent($translator->translateLivewireJson($content));
            } else {
                $response->setContent($translator->translate($content));
            }
        } catch (\Throwable) {
        }

        return $response;
    }

    private static function dictionary(): ?array
    {
        if (self::$loaded) {
            return self::$dict;
        }

        self::$loaded = true;
        try {
            $path = base_path('resources/i18n/tr-ui.json');
            if (! is_file($path)) {
                return null;
            }
            $data = json_decode((string) file_get_contents($path), true);
            if (! is_array($data)) {
                return null;
            }
            self::$dict = $data;
        } catch (\Throwable) {
            self::$dict = null;
        }

        return self::$dict;
    }
}
