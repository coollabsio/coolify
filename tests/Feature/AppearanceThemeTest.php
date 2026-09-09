<?php

test('custom color theme is available and applied across theme controls', function () {
    $appearance = file_get_contents(resource_path('views/livewire/profile/appearance.blade.php'));
    $accountMenu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));
    $component = file_get_contents(resource_path('views/components/theme-controls.blade.php'));
    $picker = file_get_contents(resource_path('views/components/theme-controls/picker.blade.php'));
    $layout = file_get_contents(resource_path('views/layouts/base.blade.php'));
    $deploymentLogs = file_get_contents(resource_path('views/livewire/project/application/deployment/show.blade.php'));
    $serverTiming = file_get_contents(resource_path('views/components/server-timing-hud.blade.php'));
    $terminal = file_get_contents(resource_path('js/terminal.js'));
    $styles = file_get_contents(resource_path('css/app.css'));
    $utilities = file_get_contents(resource_path('css/utilities.css'));

    expect($appearance)
        ->toContain('<x-theme-controls variant="full"')
        ->and($accountMenu)
        ->toContain('<x-theme-controls variant="menu"')
        ->and($component)
        ->toContain("['value' => 'custom', 'label' => 'Custom'")
        ->toContain('<x-theme-controls.picker')
        ->and($picker)
        ->toContain('type="color"')
        ->toContain('@input="previewThemeColor($event.target.value)"')
        ->toContain('@change="saveThemeColor($event.target.value)"')
        ->toContain('aria-label="Custom theme color"')
        ->toContain('setCustomMode')
        ->and($layout)
        ->toContain('window.themeControls')
        ->toContain('requestAnimationFrame(() =>')
        ->toContain("localStorage.setItem('themeColor', color)")
        ->toContain("this.theme === 'custom'")
        ->and($layout)
        ->toContain("t === 'custom'")
        ->toContain("localStorage.themeColor || '#6b16ed'")
        ->toContain('--theme-accent-foreground')
        ->toContain('window.applyStoredTheme')
        ->toContain("document.addEventListener('livewire:navigated', window.applyStoredTheme)")
        ->and($styles)
        ->toContain('html[data-theme="custom"]')
        ->toContain('--theme-base-color: oklch(49.65% 0.2709 289.33);')
        ->toContain('--theme-bright-color: color-mix(in srgb, var(--theme-base-color) 85%, oklch(100% 0 0));')
        ->toContain('--color-accent: var(--theme-bright-color);')
        ->toContain('--color-coollabs: var(--theme-bright-color);')
        ->toContain('--color-warning: var(--theme-bright-color);')
        ->toContain('--color-accent-foreground: var(--theme-accent-foreground);')
        ->toContain('--color-fg-dim: oklch(91.08% 0.0157 306.4);')
        ->toContain('--color-fg-faint: oklch(87.24% 0.0188 306.63);')
        ->toContain('html[data-theme="custom"] .control-selected')
        ->toContain('--theme-scrollbar-thumb: color-mix(in srgb, var(--theme-bright-color) 70%, var(--theme-accent-foreground));')
        ->toContain('--theme-border-color: color-mix(in oklab, var(--theme-base-color) 42%, oklch(44.19% 0.0146 285.79));')
        ->toContain('--theme-fg-on-surface: oklch(from var(--theme-base-color) clamp(0.72, l, 0.86) min(c, 0.13) h);')
        ->toContain('--theme-placeholder-color: oklch(from var(--theme-base-color) 0.62 min(c, 0.03) h);')
        ->toContain('html[data-theme="custom"] *')
        ->toContain('scrollbar-color: var(--theme-scrollbar-thumb) var(--color-panel);')
        ->toContain('html[data-theme="custom"] *::-webkit-scrollbar-thumb')
        ->toContain('html[data-theme="custom"] .application-settings-navigation')
        ->toContain('scrollbar-gutter: stable;')
        ->toContain('--color-panel: color-mix(in oklab, var(--theme-base-color) 14%, oklch(15.48% 0.0021 286.15));')
        ->toContain('--color-surface: color-mix(in oklab, var(--theme-base-color) 18%, oklch(17.35% 0.0020 286.18));')
        ->toContain('--coollabs-elevated: var(--color-surface);')
        ->toContain('--coollabs-recessed: var(--color-raised);')
        ->toContain('--coollabs-base: color-mix(in oklab, var(--theme-base-color) 22%, oklch(17.81% 0.0020 286.19));')
        ->toContain('--color-content-surface: var(--coollabs-base);')
        ->toContain('--coollabs-fill: color-mix(in oklab, var(--theme-base-color) 32%, oklch(20.99% 0.0039 286.06));')
        ->toContain('--color-nav-text: oklch(91.08% 0.0157 306.4);')
        ->toContain('--color-nav-muted: oklch(87.24% 0.0188 306.63);')
        ->toContain('--color-log: color-mix(in oklab, var(--theme-base-color) 20%, oklch(13.49% 0.0024 286.07));')
        ->toContain('--color-log-toolbar: color-mix(in oklab, var(--theme-base-color) 26%, oklch(17.35% 0.0020 286.18));')
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
        ->toMatch('/html\[data-theme="custom"\] input::placeholder,[^{]+\{[^}]*opacity: 1;/s')
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
