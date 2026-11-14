<?php

/**
 * Severity dots are non-text UI (WCAG 1.4.11: 3:1) on the x-status-badge
 * background, and "medium" must not read as the accent or the warning color.
 * Color math lives here so the app carries none of it.
 */

/**
 * @return array{0: float, 1: float, 2: float} linear sRGB
 */
function severityOklchToLinear(float $l, float $c, float $hueDegrees): array
{
    $a = $c * cos(deg2rad($hueDegrees));
    $b = $c * sin(deg2rad($hueDegrees));
    $lp = ($l + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
    $mp = ($l - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
    $sp = ($l - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

    return [
        4.0767416621 * $lp - 3.3077115913 * $mp + 0.2309699292 * $sp,
        -1.2684380046 * $lp + 2.6097574011 * $mp - 0.3413193965 * $sp,
        -0.0041960863 * $lp - 0.7034186147 * $mp + 1.7076147010 * $sp,
    ];
}

function severityCubeRoot(float $value): float
{
    return $value < 0 ? -((-$value) ** (1 / 3)) : $value ** (1 / 3);
}

/**
 * @param  array{0: float, 1: float, 2: float}  $rgb
 * @return array{0: float, 1: float, 2: float} OKLab
 */
function severityLinearToOklab(array $rgb): array
{
    [$r, $g, $b] = $rgb;
    $l = severityCubeRoot(0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b);
    $m = severityCubeRoot(0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b);
    $s = severityCubeRoot(0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b);

    return [
        0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s,
        1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s,
        0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s,
    ];
}

function severityEncode(float $linear): float
{
    return $linear <= 0.0031308 ? 12.92 * $linear : 1.055 * $linear ** (1 / 2.4) - 0.055;
}

function severityDecode(float $encoded): float
{
    return $encoded <= 0.04045 ? $encoded / 12.92 : (($encoded + 0.055) / 1.055) ** 2.4;
}

/**
 * Parses `oklch(56% 0.135 50)` or `#a391ff` into linear sRGB.
 *
 * @return array{0: float, 1: float, 2: float}
 */
function severityParseColor(string $value): array
{
    $value = trim($value);

    if (preg_match('/^#([0-9a-f]{6})$/i', $value, $hex)) {
        return array_map(
            fn (string $pair): float => severityDecode(hexdec($pair) / 255),
            str_split($hex[1], 2),
        );
    }

    if (preg_match('/^oklch\(\s*([\d.]+)%\s+([\d.]+)\s+([\d.]+)\s*\)$/', $value, $m)) {
        return severityOklchToLinear((float) $m[1] / 100, (float) $m[2], (float) $m[3]);
    }

    throw new InvalidArgumentException("Unsupported color: {$value}");
}

function severityInGamut(array $rgb): bool
{
    foreach ($rgb as $channel) {
        if ($channel < -0.0005 || $channel > 1.0005) {
            return false;
        }
    }

    return true;
}

function severityLuminance(array $rgb): float
{
    [$r, $g, $b] = array_map(fn (float $c): float => max(0.0, min(1.0, $c)), $rgb);

    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

function severityContrast(float $first, float $second): float
{
    return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
}

/** OKLab Euclidean distance times 100. */
function severityDeltaE(array $first, array $second): float
{
    $a = severityLinearToOklab($first);
    $b = severityLinearToOklab($second);

    return 100 * sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
}

/**
 * Value of a custom property in the first CSS block that starts with
 * $blockStart and defines it (app.css has more than one `.dark` block).
 */
function severityToken(string $css, string $blockStart, string $name): string
{
    $offset = 0;

    while (($start = strpos($css, "\n{$blockStart} {", $offset)) !== false) {
        $end = strpos($css, "\n}", $start);
        $block = substr($css, $start, $end - $start);

        if (preg_match('/^\s*'.preg_quote($name, '/').':\s*([^;]+);/m', $block, $m) === 1) {
            return $m[1];
        }

        $offset = $end;
    }

    throw new RuntimeException("{$name} not defined in {$blockStart}");
}

/**
 * Surface the badge paints over: white at 7% over a dark page surface,
 * blended in sRGB like the browser does.
 *
 * @param  array{0: float, 1: float, 2: float}  $surface  linear sRGB
 * @return array{0: float, 1: float, 2: float}
 */
function severityDarkBadgeBackground(array $surface): array
{
    return array_map(
        fn (float $channel): float => severityDecode(0.93 * severityEncode($channel) + 0.07),
        $surface,
    );
}

beforeEach(function () {
    $this->css = file_get_contents(__DIR__.'/../../../resources/css/app.css');
    $this->light = fn (string $name): array => severityParseColor(severityToken($this->css, '@theme', $name));
    $this->dark = fn (string $name): array => severityParseColor(severityToken($this->css, '.dark', $name));
    // The dark page surfaces live in @theme; the page is dark by default.
    $this->darkSurface = fn (string $name): array => severityParseColor(severityToken($this->css, '@theme', $name));
});

dataset('severity tokens', ['--color-severity-high', '--color-severity-medium']);

it('keeps the light severity dot at 3:1 or more on the badge background', function (string $token) {
    $color = ($this->light)($token);
    // x-status-badge: bg-neutral-100 is oklch(97% 0 0). White cards are lighter.
    $badge = severityParseColor('oklch(97% 0 0)');

    expect(severityInGamut($color))->toBeTrue()
        ->and(severityContrast(severityLuminance($color), severityLuminance($badge)))->toBeGreaterThanOrEqual(3.0);
})->with('severity tokens');

it('keeps the dark severity dot at 3:1 or more on the badge over every dark surface', function (string $token) {
    $color = ($this->dark)($token);

    expect(severityInGamut($color))->toBeTrue();

    foreach (['--color-app', '--color-panel', '--color-surface', '--color-raised', '--color-selected'] as $surfaceToken) {
        $badge = severityDarkBadgeBackground(($this->darkSurface)($surfaceToken));

        expect(severityContrast(severityLuminance($color), severityLuminance($badge)))
            ->toBeGreaterThanOrEqual(3.0, "{$token} on {$surfaceToken}");
    }
})->with('severity tokens');

it('keeps the critical dot (error) at 3:1 or more in both themes', function () {
    $lightBadge = severityParseColor('oklch(97% 0 0)');
    $lightError = severityParseColor(severityToken($this->css, 'html:not(.dark)', '--color-error'));

    expect(severityContrast(severityLuminance($lightError), severityLuminance($lightBadge)))->toBeGreaterThanOrEqual(3.0);

    foreach (['--color-app', '--color-panel', '--color-surface', '--color-raised', '--color-selected'] as $surfaceToken) {
        $badge = severityDarkBadgeBackground(($this->darkSurface)($surfaceToken));

        expect(severityContrast(severityLuminance(($this->dark)('--color-error')), severityLuminance($badge)))
            ->toBeGreaterThanOrEqual(3.0, "error on {$surfaceToken}");
    }
});

it('keeps medium visibly different from the accent and the warning color', function () {
    $css = $this->css;
    $theme = fn (string $name): array => severityParseColor(severityToken($css, '@theme', $name));
    $lightWarning = severityParseColor(severityToken($css, 'html:not(.dark)', '--color-warning'));

    $lightMedium = $theme('--color-severity-medium');
    $darkMedium = ($this->dark)('--color-severity-medium');

    // Light: accent purple and the darkened warning. Dark: the brand accent
    // (--color-accent is --color-brand), its light tint, and the warning yellow.
    $lightAgainst = [
        'light accent' => severityParseColor(severityToken($css, '@theme', '--color-accent')),
        'light warning' => $lightWarning,
    ];
    $darkAgainst = [
        'dark accent (brand)' => $theme('--color-brand'),
        'dark accent text (brand-light)' => $theme('--color-brand-light'),
        'dark warning' => $theme('--color-warning'),
    ];

    foreach ($lightAgainst as $name => $other) {
        expect(severityDeltaE($lightMedium, $other))->toBeGreaterThan(10.0, "light medium vs {$name}");
    }
    foreach ($darkAgainst as $name => $other) {
        expect(severityDeltaE($darkMedium, $other))->toBeGreaterThan(10.0, "dark medium vs {$name}");
    }
    // Warning is yellow in dark and brown in light, so check each against both.
    expect(severityDeltaE($lightMedium, $theme('--color-warning')))->toBeGreaterThan(10.0)
        ->and(severityDeltaE($darkMedium, $lightWarning))->toBeGreaterThan(10.0);
});

it('keeps high distinct from medium and from the warning color', function () {
    $css = $this->css;
    $theme = fn (string $name): array => severityParseColor(severityToken($css, '@theme', $name));

    expect(severityDeltaE($theme('--color-severity-high'), $theme('--color-severity-medium')))->toBeGreaterThan(10.0)
        ->and(severityDeltaE(($this->dark)('--color-severity-high'), ($this->dark)('--color-severity-medium')))->toBeGreaterThan(10.0);
});
