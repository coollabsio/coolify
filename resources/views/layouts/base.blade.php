<!DOCTYPE html>
<html data-theme="dark" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<script>
    // Immediate theme application - runs before any rendering
    (function () {
        const t = localStorage.theme || 'dark';
        const d = t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList[d ? 'add' : 'remove']('dark');
        document.documentElement.setAttribute('data-theme', d ? 'dark' : 'light');
    })();
</script>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#ffffff" id="theme-color-meta" />
    <meta name="color-scheme" content="dark light" />
    <meta name="Description" content="Coolify: An open-source & self-hostable Heroku / Netlify / Vercel alternative" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:site" content="@coolifyio" />
    <meta name="twitter:title" content="Coolify" />
    <meta name="twitter:description" content="An open-source & self-hostable Heroku / Netlify / Vercel alternative." />
    <meta name="twitter:image" content="https://cdn.coollabs.io/assets/coolify/og-image.png" />
    <meta property="og:type" content="website" />
    <meta property="og:url" content="https://coolify.io" />
    <meta property="og:title" content="Coolify" />
    <meta property="og:description" content="An open-source & self-hostable Heroku / Netlify / Vercel alternative." />
    <meta property="og:site_name" content="Coolify" />
    <meta property="og:image" content="https://cdn.coollabs.io/assets/coolify/og-image.png" />
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
        const isDark = t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
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
    @auth
        <div
            x-data="{
                open: false,
                tasks: [],
                loading: false,
                async fetchTasks() {
                    this.loading = true;
                    try {
                        const response = await fetch('{{ route('compression.tasks') }}', {
                            method: 'GET',
                            headers: { 'Accept': 'application/json' }
                        });
                        if (!response.ok) {
                            return;
                        }
                        const payload = await response.json();
                        this.tasks = Array.isArray(payload.tasks) ? payload.tasks : [];
                    } catch (e) {
                    } finally {
                        this.loading = false;
                    }
                }
            }"
            x-init="fetchTasks(); setInterval(() => fetchTasks(), 10000)"
            class="fixed bottom-4 right-4 z-[10000]">
            <div class="relative" @click.outside="open = false">
                <button type="button" @click="open = !open; if (open) fetchTasks()"
                    class="rounded bg-coollabs px-3 py-2 text-xs font-semibold text-white shadow-lg hover:opacity-90">
                    Compression Tasks (<span x-text="tasks.length"></span>)
                </button>
                <div x-show="open" x-cloak
                    class="absolute bottom-12 right-0 w-[30rem] max-h-[28rem] overflow-auto rounded border border-coolgray-300 dark:border-coolgray-600 bg-white dark:bg-coolgray-100 p-3 shadow-2xl">
                    <div class="mb-2 flex items-center justify-between">
                        <h4 class="text-sm font-semibold dark:text-white">Background Compression Tasks</h4>
                        <button type="button" @click="fetchTasks()"
                            class="rounded bg-gray-700 px-2 py-1 text-[11px] text-white hover:opacity-90">Refresh</button>
                    </div>
                    <template x-if="loading">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Loading...</p>
                    </template>
                    <template x-if="!loading && tasks.length === 0">
                        <p class="text-xs text-gray-500 dark:text-gray-400">No compression tasks.</p>
                    </template>
                    <div class="space-y-2" x-show="!loading && tasks.length > 0">
                        <template x-for="task in tasks" :key="task.id">
                            <div class="rounded border border-coolgray-300 dark:border-coolgray-600 p-2">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="truncate text-xs font-semibold dark:text-white" x-text="task.archive_name || 'archive.zip'"></p>
                                    <span class="text-[11px]"
                                        :class="task.status === 'completed' ? 'text-green-500' : (task.status === 'failed' ? 'text-red-500' : 'text-yellow-500')"
                                        x-text="(task.status || 'running').toUpperCase()"></span>
                                </div>
                                <p class="truncate text-[11px] text-gray-500 dark:text-gray-400" x-text="'Dir: ' + (task.directory || '/')"></p>
                                <p class="break-words text-[11px] text-gray-500 dark:text-gray-400" x-text="task.last_message || ''"></p>
                                <div class="mt-2">
                                    <a :href="task.open_url || '#'" class="rounded bg-blue-600 px-2 py-1 text-[11px] text-white hover:opacity-90">Open Location</a>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    @endauth
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
            if (theme == 'dark') {
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
            window.Echo = new Echo({
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

        function changePasswordFieldType(event) {
            let element = event.target
            for (let i = 0; i < 10; i++) {
                if (element.className === "relative") {
                    break;
                }
                element = element.parentElement;
            }
            element = element.children[1];
            if (element.nodeName === 'INPUT' || element.nodeName === 'TEXTAREA') {
                if (element.type === 'password') {
                    element.type = 'text';
                    if (element.disabled) return;
                    element.classList.add('truncate');
                    this.type = 'text';
                } else {
                    element.type = 'password';
                    if (element.disabled) return;
                    element.classList.remove('truncate');
                    this.type = 'password';
                }
            }
        }

        function copyToClipboard(text) {
            navigator?.clipboard?.writeText(text) && window.Livewire.dispatch('success', 'Copied to clipboard.');
        }
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