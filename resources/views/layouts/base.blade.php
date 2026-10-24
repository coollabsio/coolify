<!DOCTYPE html>
<html data-theme="dark" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<script data-navigate-once>
    // Immediate theme application - runs before any rendering
    (function () {
        // The OS color picker only speaks hex. Convert it once so the whole theme
        // cascade is authored in OKLCH (sRGB -> linear -> OKLab -> OKLCH).
        const srgbToLinear = (c) => c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
        // localStorage can hold values from older versions or manual edits, so accept
        // only #rgb / #rrggbb and fall back to the default color for anything else.
        window.normalizeThemeColor = (color) => {
            const hex = String(color ?? '').trim().replace(/^#/, '').toLowerCase();
            if (/^[a-f\d]{6}$/.test(hex)) return `#${hex}`;
            if (/^[a-f\d]{3}$/.test(hex)) return `#${hex.replace(/./g, '$&$&')}`;
            return '#6b16ed';
        };
        window.hexToOklch = (hex) => {
            const [r, g, b] = window.normalizeThemeColor(hex).match(/[a-f\d]{2}/gi).map((channel) => srgbToLinear(parseInt(channel, 16) / 255));
            const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
            const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
            const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);
            const okL = 0.2104542553 * l + 0.7936177850 * m - 0.0040720468 * s;
            const okA = 1.9779984951 * l - 2.4285922050 * m + 0.4505937099 * s;
            const okB = 0.0259040371 * l + 0.7827717662 * m - 0.8086757660 * s;
            const chroma = Math.sqrt(okA * okA + okB * okB);
            let hue = Math.atan2(okB, okA) * 180 / Math.PI;
            if (hue < 0) hue += 360;
            return `oklch(${(okL * 100).toFixed(2)}% ${chroma.toFixed(4)} ${hue.toFixed(2)})`;
        };
        window.themeAccentForeground = (color) => {
            const channels = window.normalizeThemeColor(color).match(/[a-f\d]{2}/gi).map(channel => parseInt(channel, 16) * 0.85 + 255 * 0.15);
            const luminance = channels
                .map(channel => channel / 255)
                .map(channel => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4)
                .reduce((sum, channel, index) => sum + channel * [0.2126, 0.7152, 0.0722][index], 0);

            return luminance > 0.179 ? 'oklch(0% 0 0)' : 'oklch(100% 0 0)';
        };
        // Custom theme: four colors. Its share code is "#sidebar,#selected,#accent,#background".
        // Every text color is derived from the color under it, so text stays readable.
        const strictHex = (color) => {
            const hex = String(color ?? '').trim().replace(/^#/, '').toLowerCase();
            if (/^[a-f\d]{6}$/.test(hex)) return `#${hex}`;
            if (/^[a-f\d]{3}$/.test(hex)) return `#${hex.replace(/./g, '$&$&')}`;
            return null;
        };
        const relativeLuminance = (hex) => hex.match(/[a-f\d]{2}/gi)
            .map((channel) => srgbToLinear(parseInt(channel, 16) / 255))
            .reduce((sum, channel, index) => sum + channel * [0.2126, 0.7152, 0.0722][index], 0);
        const mixHex = (from, to, amount) => `#${[1, 3, 5].map((offset) => {
            const start = parseInt(from.slice(offset, offset + 2), 16);
            const end = parseInt(to.slice(offset, offset + 2), 16);
            return Math.round(start + (end - start) * amount).toString(16).padStart(2, '0');
        }).join('')}`;
        // True when light text has more contrast on this color than dark text (WCAG ratios).
        window.isDarkColor = (hex) => {
            const luminance = relativeLuminance(window.normalizeThemeColor(hex));
            return 1.05 / (luminance + 0.05) >= (luminance + 0.05) / (relativeLuminance('#18181b') + 0.05);
        };
        window.readableTextOn = (hex) => window.isDarkColor(hex) ? '#f4f4f5' : '#18181b';
        window.customThemeKeys = ['sidebar', 'selected', 'accent', 'background'];
        window.serializeCustomTheme = (theme) => window.customThemeKeys.map((key) => theme[key]).join(',');
        // A theme from a share code, or null when the text is not four hex colors.
        window.parseCustomTheme = (code) => {
            const colors = String(code ?? '').split(/[\s,]+/).filter(Boolean).map(strictHex);
            if (colors.length !== 4 || colors.includes(null)) return null;
            return Object.fromEntries(window.customThemeKeys.map((key, index) => [key, colors[index]]));
        };
        // The saved theme. Older versions saved one color and a light/dark mode; build the four colors from them.
        window.storedCustomTheme = () => {
            const saved = window.parseCustomTheme(localStorage.customTheme);
            if (saved) return saved;
            const accent = window.normalizeThemeColor(localStorage.themeColor);
            return localStorage.customMode === 'light'
                ? { sidebar: mixHex('#f4f4f5', accent, 0.06), selected: mixHex('#ffffff', accent, 0.16), accent, background: mixHex('#fafafa', accent, 0.03) }
                : { sidebar: mixHex('#0c0c0e', accent, 0.1), selected: mixHex('#1c1c1f', accent, 0.3), accent, background: mixHex('#0f0f11', accent, 0.12) };
        };
        window.customThemePresets = [
            // The built-in Dark theme, not a custom theme. Its colors start the fields when the user edits it. The sidebar is
            // the color that the Dark sidebar shows: #120e1a with its 9% purple wash.
            { name: 'Coolify', theme: null, swatch: { sidebar: '#1a0f2d', selected: '#1c1c1c', accent: '#6b16ed', background: '#0a0a0a' } },
            { name: 'Midnight', theme: { sidebar: '#0f172a', selected: '#2e4a7d', accent: '#f2458f', background: '#1a2338' } },
            { name: 'Forest', theme: { sidebar: '#12342b', selected: '#2c6e57', accent: '#f4b860', background: '#1b2a25' } },
            { name: 'Ember', theme: { sidebar: '#3b1410', selected: '#8f2d1f', accent: '#ffb86b', background: '#241816' } },
            { name: 'Slate', theme: { sidebar: '#2b3038', selected: '#4a5361', accent: '#6cc4f5', background: '#353b45' } },
            { name: 'Nord', theme: { sidebar: '#2e3440', selected: '#4c566a', accent: '#88c0d0', background: '#3b4252' } },
            { name: 'Dracula', theme: { sidebar: '#21222c', selected: '#44475a', accent: '#bd93f9', background: '#282a36' } },
            { name: 'GitHub Dark', theme: { sidebar: '#010409', selected: '#21262d', accent: '#2f81f7', background: '#0d1117' } },
            { name: 'Aubergine', theme: { sidebar: '#3f0e40', selected: '#1164a3', accent: '#1164a3', background: '#ffffff' } },
            { name: 'GitHub Light', theme: { sidebar: '#f6f8fa', selected: '#dde4ec', accent: '#0969da', background: '#ffffff' } },
            { name: 'Paper', theme: { sidebar: '#ebe6da', selected: '#d9d1bf', accent: '#c2410c', background: '#f7f4ec' } },
            { name: 'Blossom', theme: { sidebar: '#fde8f1', selected: '#f9c4dc', accent: '#d61f69', background: '#fff7fa' } },
        ];
        // Sets the variables that app.css derives the custom theme from. The sidebar gets its own
        // light/dark scheme (data-sidebar-scheme) when it differs from the page.
        window.applyCustomTheme = (theme) => {
            const root = document.documentElement;
            const isDark = window.isDarkColor(theme.background);
            const sidebarIsDark = window.isDarkColor(theme.sidebar);
            Object.entries({
                '--theme-bg': theme.background,
                '--theme-text': window.readableTextOn(theme.background),
                '--theme-light': isDark ? '0' : '1',
                '--theme-sidebar': theme.sidebar,
                '--theme-sidebar-text': window.readableTextOn(theme.sidebar),
                '--theme-sidebar-light': sidebarIsDark ? '0' : '1',
                '--theme-selected': theme.selected,
                '--theme-selected-text': window.readableTextOn(theme.selected),
                '--theme-base-color': window.hexToOklch(theme.accent),
                '--theme-accent-foreground': window.themeAccentForeground(theme.accent),
            }).forEach(([name, value]) => root.style.setProperty(name, value));
            root.dataset.theme = 'custom';
            root.classList.toggle('dark', isDark);
            if (sidebarIsDark === isDark) {
                delete root.dataset.sidebarScheme;
            } else {
                root.dataset.sidebarScheme = sidebarIsDark ? 'dark' : 'light';
            }
            document.querySelector('meta[name=theme-color]')?.setAttribute('content', theme.background);
        };
        window.applyStoredTheme = () => {
            const root = document.documentElement;
            const theme = localStorage.theme === 'purple' ? 'custom' : (localStorage.theme || 'dark');
            root.dataset.navStyle = localStorage.navStyle === 'floating' ? 'floating' : 'default';
            root.dataset.navItems = localStorage.navItems === 'flat' ? 'flat' : '3d';
            localStorage.theme = theme;
            if (localStorage.themeColor) localStorage.themeColor = window.normalizeThemeColor(localStorage.themeColor);
            if (theme === 'custom') {
                window.applyCustomTheme(window.storedCustomTheme());
                return;
            }

            const isDark = theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            root.classList.toggle('dark', isDark);
            root.dataset.theme = isDark ? 'dark' : 'light';
            delete root.dataset.sidebarScheme;
            root.style.setProperty('--theme-base-color', window.hexToOklch(localStorage.themeColor));
            root.style.setProperty('--theme-accent-foreground', window.themeAccentForeground(localStorage.themeColor));
            document.querySelector('meta[name=theme-color]')?.setAttribute('content', isDark ? '#101010' : '#ffffff');
        };
        // Time lines for chart tooltips. By default only the browser time zone is shown;
        // the "Chart times" option on the Appearance page adds the UTC time.
        window.chartTooltipTime = (timestamp, { seconds = true } = {}) => {
            const format = (timeZone) => new Date(timestamp).toLocaleString(undefined, {
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                ...(seconds ? { second: '2-digit' } : {}),
                hourCycle: 'h23',
                ...(timeZone ? { timeZone, timeZoneName: 'short' } : {}),
            });
            if (localStorage.getItem('chartTimes') !== 'utc') {
                return `<div class="apexcharts-tooltip-custom-title">${format()}</div>`;
            }
            const localTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
            return `<div class="apexcharts-tooltip-custom-title">Your time: ${format(localTimeZone)}</div>
                <div class="apexcharts-tooltip-custom-title">UTC: ${format('UTC')}</div>`;
        };
        // Shows the tooltip at the same time on every chart of a group while one of them is hovered.
        // ApexCharts' own chart.group needs equal widths and equal x ranges, so it does not
        // fit side-by-side cards. Instead, replay the hover as a mouse event at the matching x.
        // Charts can join a group from separate scripts; charts removed from the page drop out.
        window.chartSyncGroups ??= {};
        window.syncChartTooltips = (group, charts) => {
            const grid = chart => chart.el.querySelector('.apexcharts-grid')?.getBoundingClientRect();
            const hoverArea = chart => chart.el.querySelector('.apexcharts-svg');
            const hide = chart => hoverArea(chart)?.dispatchEvent(new MouseEvent('mouseout', { clientX: 0, clientY: -1 }));
            const peers = source => window.chartSyncGroups[group].filter(chart => chart !== source && chart.el.isConnected);

            window.chartSyncGroups[group] = [
                ...(window.chartSyncGroups[group] ?? []).filter(chart => chart.el.isConnected),
                ...charts,
            ];

            charts.forEach(source => {
                source.el.addEventListener('mousemove', event => {
                    // Replayed events are untrusted; skip them so charts do not echo each other.
                    const rect = grid(source);
                    const { minX, maxX } = source.w.globals;
                    if (!event.isTrusted || !rect?.width || !(maxX > minX)) {
                        return;
                    }
                    // ApexCharts hides the source tooltip outside the plot grid; hide the peers too.
                    if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) {
                        peers(source).forEach(hide);
                        return;
                    }
                    const timestamp = minX + (event.clientX - rect.left) / rect.width * (maxX - minX);

                    peers(source).forEach(chart => {
                        const target = grid(chart);
                        const { minX: min, maxX: max } = chart.w.globals;
                        if (!target?.width || !(max > min) || timestamp < min || timestamp > max) {
                            hide(chart);
                            return;
                        }
                        hoverArea(chart)?.dispatchEvent(new MouseEvent('mousemove', {
                            clientX: target.left + (timestamp - min) / (max - min) * target.width,
                            clientY: target.top + target.height / 2,
                        }));
                    });
                });
                source.el.addEventListener('mouseleave', () => peers(source).forEach(hide));
            });
        };
        // Alpine state for the theme controls on the Appearance page (x-data="themeControls()").
        window.themeControls = () => ({
            theme: localStorage.getItem('theme') === 'purple' ? 'custom' : (localStorage.getItem('theme') || 'dark'),
            customTheme: window.storedCustomTheme(),
            pageWidth: localStorage.getItem('pageWidth') || 'centered',
            navStyle: localStorage.getItem('navStyle') === 'floating' ? 'floating' : 'default',
            navItems: localStorage.getItem('navItems') === 'flat' ? 'flat' : '3d',
            chartTimes: localStorage.getItem('chartTimes') === 'utc' ? 'utc' : 'local',
            presets: window.customThemePresets,
            // The custom theme dialog edits a draft; Save applies it. A null draft is the built-in Dark theme.
            dialogOpen: false,
            draft: null,
            get draftColors() {
                return this.draft ?? window.customThemePresets[0].swatch;
            },
            shareCode: '',
            shareCodeInvalid: false,
            shareCodeCopied: false,
            setTheme(type) {
                this.theme = type;
                localStorage.setItem('theme', type);
                window.applyStoredTheme();
            },
            openCustomDialog() {
                this.setDraft(this.theme === 'custom' ? { ...this.customTheme } : null);
                this.dialogOpen = true;
            },
            setDraft(theme) {
                this.draft = theme;
                this.shareCode = window.serializeCustomTheme(this.draftColors);
                this.shareCodeInvalid = false;
            },
            setDraftColor(key, value) {
                const theme = window.parseCustomTheme(window.serializeCustomTheme({ ...this.draftColors, [key]: value }));
                if (theme) {
                    this.setDraft(theme);
                }
            },
            readShareCode(code) {
                const theme = window.parseCustomTheme(code);
                if (theme) {
                    this.setDraft(theme);
                    return;
                }
                this.shareCodeInvalid = code.trim() !== '';
            },
            async copyShareCode() {
                try {
                    await navigator.clipboard.writeText(this.shareCode);
                    this.shareCodeCopied = true;
                    setTimeout(() => this.shareCodeCopied = false, 1500);
                } catch {
                    this.$refs.shareCode?.select();
                }
            },
            isDraftPreset(preset) {
                return preset.theme === null || this.draft === null
                    ? preset.theme === this.draft
                    : window.serializeCustomTheme(preset.theme) === window.serializeCustomTheme(this.draft);
            },
            textOn(color) {
                return window.readableTextOn(color);
            },
            accentTextOn(color) {
                return window.themeAccentForeground(color);
            },
            saveCustomTheme() {
                this.dialogOpen = false;
                if (this.draft === null) {
                    localStorage.removeItem('customTheme');
                    this.setTheme('dark');
                    return;
                }
                this.customTheme = { ...this.draft };
                localStorage.setItem('customTheme', window.serializeCustomTheme(this.customTheme));
                localStorage.setItem('themeColor', this.customTheme.accent);
                this.setTheme('custom');
            },
            setWidth(width) {
                this.pageWidth = width;
                localStorage.setItem('pageWidth', width);
                window.dispatchEvent(new CustomEvent('page-width-changed', { detail: width }));
            },
            setNavStyle(style) {
                this.navStyle = style;
                localStorage.setItem('navStyle', style);
                document.documentElement.dataset.navStyle = style;
            },
            setNavItems(style) {
                this.navItems = style;
                localStorage.setItem('navItems', style);
                document.documentElement.dataset.navItems = style;
            },
            setChartTimes(value) {
                this.chartTimes = value;
                localStorage.setItem('chartTimes', value);
            },
        });

        document.addEventListener('livewire:navigated', window.applyStoredTheme);
        window.applyStoredTheme();
    })();
</script>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#101010" id="theme-color-meta" />
    <meta name="color-scheme" content="dark light" />
    <meta name="Description" content="Coolify: An open-source & self-hostable Heroku / Netlify / Vercel alternative" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:site" content="@coolifyio" />
    <meta name="twitter:title" content="Coolify" />
    <meta name="twitter:description" content="An open-source & self-hostable Heroku / Netlify / Vercel alternative." />
    <meta name="twitter:image" content="https://cdn.coollabs.io/og-images/coolify.png" />
    <meta property="og:type" content="website" />
    <meta property="og:url" content="https://coolify.io" />
    <meta property="og:title" content="Coolify" />
    <meta property="og:description" content="An open-source & self-hostable Heroku / Netlify / Vercel alternative." />
    <meta property="og:site_name" content="Coolify" />
    <meta property="og:image" content="https://cdn.coollabs.io/og-images/coolify.png" />
    @use('App\Models\InstanceSettings')
    @php

        $instanceSettings = instanceSettings();
        $name = null;

        if ($instanceSettings) {
            $displayName = $instanceSettings->getTitleDisplayName();

            if (strlen($displayName) > 0) {
                $name = $displayName . ' ';
            }
        }
    @endphp
    <title>{{ $name }}{{ $title ?? 'Coolify' }}</title>
    @env('local')
        <link rel="icon" href="{{ asset('coolify-logo-dev-transparent.png') }}" type="image/png" />
    @else
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32" />
        <link rel="icon" href="{{ asset('coolify-logo.svg') }}" type="image/svg+xml" />
    @endenv
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}" />
    <link rel="manifest" href="{{ asset('site.webmanifest') }}" />
    <meta name="apple-mobile-web-app-title" content="Coolify" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/js/app.js', 'resources/css/app.css'])
    <script>
        // Update theme-color meta tag (non-critical, can run async)
        const isDark = document.documentElement.classList.contains('dark');
        document.getElementById('theme-color-meta')?.setAttribute('content', document.documentElement.dataset.theme === 'custom'
            ? window.storedCustomTheme().background
            : (isDark ? '#101010' : '#ffffff'));
    </script>
    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>
    @if (config('app.name') == 'Coolify Cloud')
        <script defer data-domain="app.coolify.io" src="https://analytics.coollabs.io/js/plausible.js"></script>
    @endif
    @auth
        <script type="text/javascript" src="{{ URL::asset('js/echo.js') }}"></script>
        <script type="text/javascript" src="{{ URL::asset('js/pusher.js') }}"></script>
        <script type="text/javascript" src="{{ URL::asset('js/apexcharts.js') }}"></script>
        <script type="text/javascript" src="{{ URL::asset('js/purify.min.js') }}"></script>
    @endauth
</head>
@section('body')

<body class="dark:text-inherit text-black">
    <x-toast />
    <x-icon-tooltip />
    <script data-navigate-once>
        // Global HTML sanitization function using DOMPurify
        window.sanitizeHTML = function (html) {
            if (!html) return '';
            const URL_RE = /^(https?:|mailto:)/i;
            const config = {
                ALLOWED_TAGS: ['a', 'b', 'br', 'code', 'del', 'div', 'em', 'i', 'mark', 'p', 'pre', 's', 'span', 'strong',
                    'u'
                ],
                ALLOWED_ATTR: ['class', 'href', 'target', 'title', 'rel'],
                ALLOW_DATA_ATTR: false,
                FORBID_TAGS: ['script', 'object', 'embed', 'applet', 'iframe', 'form', 'input', 'button', 'select',
                    'textarea', 'details', 'summary', 'dialog', 'style'
                ],
                FORBID_ATTR: ['onerror', 'onload', 'onclick', 'onmouseover', 'onfocus', 'onblur', 'onchange',
                    'onsubmit', 'ontoggle', 'style'
                ],
                KEEP_CONTENT: true,
                RETURN_DOM: false,
                RETURN_DOM_FRAGMENT: false,
                SANITIZE_DOM: true,
                SANITIZE_NAMED_PROPS: true,
                SAFE_FOR_TEMPLATES: true,
                ALLOWED_URI_REGEXP: URL_RE
            };

            // One-time hook registration (idempotent pattern)
            if (!window.__dpLinkHook) {
                DOMPurify.addHook('afterSanitizeAttributes', node => {
                    // Remove Alpine.js directives to prevent XSS
                    if (node.hasAttributes && node.hasAttributes()) {
                        const attrs = Array.from(node.attributes);
                        attrs.forEach(attr => {
                            // Remove x-* attributes (Alpine directives)
                            if (attr.name.startsWith('x-')) {
                                node.removeAttribute(attr.name);
                            }
                            // Remove @* attributes (Alpine event shorthand)
                            if (attr.name.startsWith('@')) {
                                node.removeAttribute(attr.name);
                            }
                            // Remove :* attributes (Alpine binding shorthand)
                            if (attr.name.startsWith(':')) {
                                node.removeAttribute(attr.name);
                            }
                        });
                    }

                    // Existing link sanitization
                    if (node.nodeName === 'A' && node.hasAttribute('href')) {
                        const href = node.getAttribute('href') || '';
                        if (!URL_RE.test(href)) node.removeAttribute('href');
                        if (node.getAttribute('target') === '_blank') {
                            node.setAttribute('rel', 'noopener noreferrer');
                        }
                    }
                });
                window.__dpLinkHook = true;
            }
            return DOMPurify.sanitize(html, config);
        };

        // Initialize theme if not set
        if (!('theme' in localStorage)) {
            localStorage.theme = 'dark';
        }

        let theme = localStorage.theme
        let cpuColor = '#1e90ff'
        let ramColor = '#00ced1'
        // Chart text follows the CSS text color, so it stays readable after a theme change without a re-render
        // (for example a page that Livewire restores from its cache on Back).
        const textColor = 'currentColor'
        let editorBackground = '#181818'
        let editorTheme = 'blackboard'

        function checkTheme() {
            theme = localStorage.theme
            if (theme == 'system') {
                theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
            }
            if (theme == 'dark' || (theme == 'custom' && document.documentElement.classList.contains('dark'))) {
                cpuColor = '#1e90ff'
                ramColor = '#00ced1'
                editorBackground = '#181818'
                editorTheme = 'blackboard'
            } else {
                cpuColor = '#1e90ff'
                ramColor = '#00ced1'
                editorBackground = '#ffffff'
                editorTheme = null
            }
        }
        // Chart legend in a card header: one entry per series with its last known value.
        // items: [{ name, color, data: [[timestamp, value], ...], formatter }]
        function renderChartLegend(element, items) {
            if (!element) return;
            element.replaceChildren(...items.map(({ name, color, data, formatter }) => {
                const last = (data || []).findLast(point => point?.[1] !== null && point?.[1] !== undefined);
                const item = document.createElement('span');
                item.className = 'inline-flex items-center gap-1.5';
                const dot = document.createElement('span');
                dot.className = 'size-2 shrink-0 rounded-full';
                dot.style.backgroundColor = color;
                const label = document.createElement('span');
                label.className = 'text-neutral-500 dark:text-fg-dim';
                label.textContent = name;
                item.append(dot, label);
                if (last) {
                    const value = document.createElement('span');
                    value.className = 'font-medium tabular-nums text-black dark:text-fg';
                    value.textContent = formatter(last[1]);
                    item.append(value);
                }
                return item;
            }));
        }
        @auth
            window.Pusher = Pusher;
            const EchoConstructor = typeof Echo === 'function' ? Echo : Echo.default;
            window.Echo = new EchoConstructor({
                broadcaster: 'reverb',
                key: "{{ config('constants.pusher.app_key') }}" || 'coolify',
                wsHost: "{{ config('constants.pusher.host') }}" || window.location.hostname,
                wsPort: "{{ getRealtime() }}",
                wssPort: "{{ getRealtime() }}",
                forceTLS: window.location.protocol === 'https:',
                encrypted: true,
                enableStats: false,
                enableLogging: true,
                enabledTransports: ['ws', 'wss'],
                disableStats: true,
                // Add auto reconnection settings
                enabledTransports: ['ws', 'wss'],
                disabledTransports: ['sockjs', 'xhr_streaming', 'xhr_polling'],
                // Attempt to reconnect on connection lost
                autoReconnect: true,
                // Wait 1 second before first reconnect attempt
                reconnectionDelay: 1000,
                // Maximum delay between reconnection attempts
                maxReconnectionDelay: 1000,
                // Multiply delay by this number for each reconnection attempt
                reconnectionDelayGrowth: 1,
                // Maximum number of reconnection attempts
                maxAttempts: 15
            });
        @endauth
        let checkHealthInterval = null;
        let checkIfIamDeadInterval = null;

        document.addEventListener('livewire:init', () => {
            window.Livewire.on('reloadWindow', (timeout) => {
                if (timeout) {
                    setTimeout(() => {
                        window.location.reload();
                    }, timeout);
                    return;
                } else {
                    window.location.reload();
                }
            })
            window.Livewire.on('info', (message) => {
                if (typeof message === 'string') {
                    window.toast('Info', {
                        type: 'info',
                        description: message,
                    })
                    return;
                }
                if (message.length == 1) {
                    window.toast('Info', {
                        type: 'info',
                        description: message[0],
                    })
                } else if (message.length == 2) {
                    window.toast(message[0], {
                        type: 'info',
                        description: message[1],
                    })
                }
            })
            window.Livewire.on('error', (message) => {
                if (typeof message === 'string') {
                    window.toast('Error', {
                        type: 'danger',
                        description: message,
                    })
                    return;
                }
                if (message.length == 1) {
                    window.toast('Error', {
                        type: 'danger',
                        description: message[0],
                    })
                } else if (message.length == 2) {
                    window.toast(message[0], {
                        type: 'danger',
                        description: message[1],
                    })
                }
            })
            window.Livewire.on('warning', (message) => {
                if (typeof message === 'string') {
                    window.toast('Warning', {
                        type: 'warning',
                        description: message,
                    })
                    return;
                }
                if (message.length == 1) {
                    window.toast('Warning', {
                        type: 'warning',
                        description: message[0],
                    })
                } else if (message.length == 2) {
                    window.toast(message[0], {
                        type: 'warning',
                        description: message[1],
                    })
                }
            })
            window.Livewire.on('success', (message) => {
                if (typeof message === 'string') {
                    window.toast('Success', {
                        type: 'success',
                        description: message,
                    })
                    return;
                }
                if (message.length == 1) {
                    window.toast('Success', {
                        type: 'success',
                        description: message[0],
                    })
                } else if (message.length == 2) {
                    window.toast(message[0], {
                        type: 'success',
                        description: message[1],
                    })
                }
            })
        });
    </script>
</body>
@show

</html>
