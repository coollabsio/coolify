<!DOCTYPE html>
<html data-theme="dark" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<script data-navigate-once>
    // Immediate theme application - runs before any rendering
    (function () {
        // The OS color picker only speaks hex. Convert it once so the whole theme
        // cascade is authored in OKLCH (sRGB -> linear -> OKLab -> OKLCH).
        const srgbToLinear = (c) => c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
        window.hexToOklch = (hex) => {
            const [r, g, b] = hex.match(/[a-f\d]{2}/gi).map((channel) => srgbToLinear(parseInt(channel, 16) / 255));
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
            const channels = color.match(/[a-f\d]{2}/gi).map(channel => parseInt(channel, 16) * 0.85 + 255 * 0.15);
            const luminance = channels
                .map(channel => channel / 255)
                .map(channel => channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4)
                .reduce((sum, channel, index) => sum + channel * [0.2126, 0.7152, 0.0722][index], 0);

            return luminance > 0.179 ? 'oklch(0% 0 0)' : 'oklch(100% 0 0)';
        };
        window.applyStoredTheme = () => {
            const theme = localStorage.theme === 'purple' ? 'custom' : (localStorage.theme || 'dark');
            const themeColor = localStorage.themeColor || '#6b16ed';
            const customMode = localStorage.customMode || 'dark';
            const isDark = theme === 'dark'
                || (theme === 'custom' && customMode === 'dark')
                || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);

            localStorage.theme = theme;
            document.documentElement.classList.toggle('dark', isDark);
            document.documentElement.dataset.theme = theme === 'custom' ? 'custom' : (isDark ? 'dark' : 'light');
            document.documentElement.style.setProperty('--theme-base-color', window.hexToOklch(themeColor));
            document.documentElement.style.setProperty('--theme-accent-foreground', window.themeAccentForeground(themeColor));
            document.querySelector('meta[name=theme-color]')?.setAttribute('content', isDark ? '#101010' : '#ffffff');
        };
        // Single source for the theme controls Alpine state, shared by the
        // Appearance page and the profile dropdown via x-data="themeControls()".
        window.themeControls = () => ({
            theme: localStorage.getItem('theme') === 'purple' ? 'custom' : (localStorage.getItem('theme') || 'dark'),
            themeColor: localStorage.getItem('themeColor') || '#6b16ed',
            customMode: localStorage.getItem('customMode') || 'dark',
            pageWidth: localStorage.getItem('pageWidth') || 'full',
            themeColorFrame: null,
            pickerOpen: false,
            init() {
                localStorage.setItem('theme', this.theme);
                this.applyTheme();
            },
            chooseCustom() {
                this.setTheme('custom');
                this.pickerOpen = !this.pickerOpen;
            },
            setTheme(type) {
                this.theme = type;
                localStorage.setItem('theme', type);
                this.applyTheme();
            },
            setCustomMode(mode) {
                this.customMode = mode;
                localStorage.setItem('customMode', mode);
                if (this.theme !== 'custom') {
                    this.setTheme('custom');
                    return;
                }
                this.applyTheme();
            },
            setWidth(width) {
                this.pageWidth = width;
                localStorage.setItem('pageWidth', width);
                window.dispatchEvent(new CustomEvent('page-width-changed', { detail: width }));
            },
            previewThemeColor(color) {
                this.themeColor = color;
                if (this.theme !== 'custom') {
                    this.theme = 'custom';
                    localStorage.setItem('theme', 'custom');
                }
                document.documentElement.dataset.theme = 'custom';
                document.documentElement.classList.toggle('dark', this.customMode === 'dark');
                if (this.themeColorFrame) {
                    return;
                }
                this.themeColorFrame = requestAnimationFrame(() => {
                    document.documentElement.style.setProperty('--theme-base-color', window.hexToOklch(this.themeColor));
                    document.documentElement.style.setProperty('--theme-accent-foreground', window.themeAccentForeground(this.themeColor));
                    this.themeColorFrame = null;
                });
            },
            saveThemeColor(color) {
                this.previewThemeColor(color);
                localStorage.setItem('themeColor', color);
                localStorage.setItem('theme', 'custom');
            },
            applyTheme() {
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                const isDark = this.theme === 'dark'
                    || (this.theme === 'custom' && this.customMode === 'dark')
                    || (this.theme === 'system' && prefersDark);
                document.documentElement.classList.toggle('dark', isDark);
                document.documentElement.dataset.theme = this.theme === 'custom' ? 'custom' : (isDark ? 'dark' : 'light');
                document.documentElement.style.setProperty('--theme-base-color', window.hexToOklch(this.themeColor));
                document.documentElement.style.setProperty('--theme-accent-foreground', window.themeAccentForeground(this.themeColor));
                document.querySelector('meta[name=theme-color]')?.setAttribute('content', isDark ? '#101010' : '#ffffff');
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
        <link rel="icon" href="{{ asset('coolify-logo.svg') }}" type="image/svg+xml" />
    @endenv
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/js/app.js', 'resources/css/app.css'])
    <script>
        // Update theme-color meta tag (non-critical, can run async)
        const t = localStorage.theme || 'dark';
        const isDark = t === 'dark' || t === 'custom' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
        document.getElementById('theme-color-meta')?.setAttribute('content', isDark ? '#101010' : '#ffffff');
    </script>
    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>
    @if (config('app.name') == 'Coolify Cloud')
        <script defer data-domain="app.coolify.io" src="https://analytics.coollabs.io/js/plausible.js"></script>
        <script src="https://js.sentry-cdn.com/0f8593910512b5cdd48c6da78d4093be.min.js" crossorigin="anonymous"></script>
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
        let textColor = '#ffffff'
        let editorBackground = '#181818'
        let editorTheme = 'blackboard'

        function checkTheme() {
            theme = localStorage.theme
            if (theme == 'system') {
                theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
            }
            if (theme == 'dark' || theme == 'custom') {
                cpuColor = '#1e90ff'
                ramColor = '#00ced1'
                textColor = '#ffffff'
                editorBackground = '#181818'
                editorTheme = 'blackboard'
            } else {
                cpuColor = '#1e90ff'
                ramColor = '#00ced1'
                textColor = '#000000'
                editorBackground = '#ffffff'
                editorTheme = null
            }
        }
        @auth
            window.Pusher = Pusher;
            const EchoConstructor = typeof Echo === 'function' ? Echo : Echo.default;
            window.Echo = new EchoConstructor({
                broadcaster: 'pusher',
                cluster: "{{ config('constants.pusher.host') }}" || window.location.hostname,
                key: "{{ config('constants.pusher.app_key') }}" || 'coolify',
                wsHost: "{{ config('constants.pusher.host') }}" || window.location.hostname,
                wsPort: "{{ getRealtime() }}",
                wssPort: "{{ getRealtime() }}",
                forceTLS: false,
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
