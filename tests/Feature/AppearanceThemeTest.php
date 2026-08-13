<?php

test('custom color theme is available and applied across theme controls', function () {
    $appearance = file_get_contents(resource_path('views/livewire/profile/appearance.blade.php'));
    $accountMenu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));
    $layout = file_get_contents(resource_path('views/layouts/base.blade.php'));
    $deploymentLogs = file_get_contents(resource_path('views/livewire/project/application/deployment/show.blade.php'));
    $serverTiming = file_get_contents(resource_path('views/components/server-timing-hud.blade.php'));
    $terminal = file_get_contents(resource_path('js/terminal.js'));
    $styles = file_get_contents(resource_path('css/app.css'));
    $utilities = file_get_contents(resource_path('css/utilities.css'));

    expect($appearance)
        ->toContain("['value' => 'custom', 'label' => 'Custom'")
        ->toContain('type="color"')
        ->toContain('absolute inset-0 z-10 h-full w-full cursor-pointer opacity-0')
        ->toContain('requestAnimationFrame(() =>')
        ->toContain('@input="previewThemeColor($event.target.value)"')
        ->toContain('@change="saveThemeColor($event.target.value)"')
        ->toContain("localStorage.setItem('themeColor', color)")
        ->toContain("this.theme === 'custom'")
        ->and($accountMenu)
        ->toContain("['value' => 'custom', 'label' => 'Custom']")
        ->toContain('aria-label="Custom theme color"')
        ->toContain('requestAnimationFrame(() =>')
        ->toContain('@input="previewThemeColor($event.target.value)"')
        ->toContain('@change="saveThemeColor($event.target.value)"')
        ->toContain("localStorage.setItem('themeColor', color)")
        ->toContain('absolute inset-0 z-10 h-full w-full cursor-pointer opacity-0')
        ->and($layout)
        ->toContain("t === 'custom'")
        ->toContain("localStorage.themeColor || '#6b16ed'")
        ->toContain('--theme-accent-foreground')
        ->toContain('window.applyStoredTheme')
        ->toContain("document.addEventListener('livewire:navigated', window.applyStoredTheme)")
        ->and($styles)
        ->toContain('html[data-theme="custom"]')
        ->toContain('--theme-base-color: #6b16ed;')
        ->toContain('--theme-bright-color: color-mix(in srgb, var(--theme-base-color) 85%, white);')
        ->toContain('--color-accent: var(--theme-bright-color);')
        ->toContain('--color-coollabs: var(--theme-bright-color);')
        ->toContain('--color-warning: var(--theme-bright-color);')
        ->toContain('--color-accent-foreground: var(--theme-accent-foreground);')
        ->toContain('--color-fg-dim: #e4dfea;')
        ->toContain('--color-fg-faint: #d8d2df;')
        ->toContain('html[data-theme="custom"] .control-selected')
        ->toContain('--theme-scrollbar-thumb: color-mix(in srgb, var(--theme-bright-color) 70%, var(--theme-accent-foreground));')
        ->toContain('--theme-border-color: color-mix(in oklab, var(--theme-base-color) 42%, #52525b);')
        ->toContain('--theme-placeholder-color: color-mix(in srgb, white 20%, var(--theme-base-color));')
        ->toContain('html[data-theme="custom"] *')
        ->toContain('scrollbar-color: var(--theme-scrollbar-thumb) var(--color-panel);')
        ->toContain('html[data-theme="custom"] *::-webkit-scrollbar-thumb')
        ->toContain('html[data-theme="custom"] .application-settings-navigation')
        ->toContain('scrollbar-gutter: stable;')
        ->toContain('--color-panel: color-mix(in oklab, var(--theme-base-color) 14%, #0c0c0d);')
        ->toContain('--color-surface: color-mix(in oklab, var(--theme-base-color) 18%, #101011);')
        ->toContain('--coollabs-elevated: var(--color-surface);')
        ->toContain('--coollabs-recessed: var(--color-raised);')
        ->toContain('--coollabs-base: color-mix(in oklab, var(--theme-base-color) 22%, #111112);')
        ->toContain('--color-content-surface: var(--coollabs-base);')
        ->toContain('--coollabs-fill: color-mix(in oklab, var(--theme-base-color) 32%, #18181a);')
        ->toContain('--color-nav-text: #e4dfea;')
        ->toContain('--color-nav-muted: #d8d2df;')
        ->toContain('--color-log: color-mix(in oklab, var(--theme-base-color) 20%, #080809);')
        ->toContain('--color-log-toolbar: color-mix(in oklab, var(--theme-base-color) 26%, #101011);')
        ->toContain('html[data-theme="custom"] .logs-viewer-toolbar')
        ->toContain('html[data-theme="custom"] .logs-viewer-timestamp')
        ->toContain('html[data-theme="custom"] #nprogress .bar')
        ->toContain('background: var(--theme-bright-color) !important;')
        ->toContain('html[data-theme="custom"] #nprogress .spinner-icon')
        ->toContain('html[data-theme="custom"] .loading-indicator')
        ->toContain('html[data-theme="custom"] .data-table')
        ->toContain('html[data-theme="custom"] [class~="dark:bg-white/[0.025]"]')
        ->toContain('html[data-theme="custom"] [class~="dark:bg-surface"]')
        ->toContain('background-color: var(--color-content-surface) !important;')
        ->toContain('.dark [class~="dark:border-white/[0.08]"]')
        ->toContain('.dark [class~="dark:border-white/[0.06]"]')
        ->toContain('border-color: var(--coollabs-hairline) !important;')
        ->toContain('html[data-theme="custom"] input::placeholder')
        ->toContain('color: var(--theme-placeholder-color) !important;')
        ->toMatch('/html\[data-theme="custom"\] input::placeholder,[^{]+\{[^}]*opacity: 0\.7;/s')
        ->toContain('html[data-theme="custom"] input:read-only')
        ->and($deploymentLogs)
        ->toContain('dark:bg-log')
        ->not->toContain('dark:bg-[#0d0d0d]')
        ->and($serverTiming)
        ->toContain('html[data-theme="custom"] #server-timing-hud')
        ->toContain('--sth-background: var(--color-surface);')
        ->toContain('--sth-livewire: var(--theme-bright-color);')
        ->and($utilities)
        ->toContain('text-nav-text')
        ->toContain('text-nav-muted')
        ->toContain('text-nav-active')
        ->and($terminal)
        ->toContain("dataset.theme === 'custom'")
        ->toContain("localStorage.getItem('themeColor')")
        ->toContain("attributeFilter: ['class', 'data-theme', 'style']")
        ->and($styles)
        ->toContain('html[data-theme="custom"] .application-console-shell[data-console-theme="system"]')
        ->toContain('--console-theme-background: var(--color-log);')
        ->toContain('--console-theme-border: var(--coollabs-line);');
});

test('navigation colors meet WCAG AA contrast requirements', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    $contrastRatio = function (string $foreground, string $background): float {
        $luminance = function (string $color): float {
            $channels = array_map(
                fn (string $channel): float => hexdec($channel) / 255,
                str_split(ltrim($color, '#'), 2),
            );

            $channels = array_map(
                fn (float $channel): float => $channel <= 0.04045
                    ? $channel / 12.92
                    : (($channel + 0.055) / 1.055) ** 2.4,
                $channels,
            );

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };

        [$lighter, $darker] = collect([$luminance($foreground), $luminance($background)])
            ->sortDesc()
            ->values()
            ->all();

        return ($lighter + 0.05) / ($darker + 0.05);
    };

    $themes = [
        [['#525252', '#666666', '#171717'], '#ffffff'],
        [['#a8a8b0', '#7a7a84', '#f2f2f2'], '#0c0c0d'],
        [['#e4dfea', '#d8d2df', '#ffffff', '#e4dfea', '#d8d2df'], '#58585a'],
    ];

    foreach ($themes as [$colors, $background]) {
        foreach ($colors as $color) {
            expect($styles)->toContain($color);
            expect($contrastRatio($color, $background))->toBeGreaterThanOrEqual(4.5);
        }
    }
});
