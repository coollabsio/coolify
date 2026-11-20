<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title')</title>
    <style>
        :root {
            color-scheme: light dark;
            --canvas: #fafafa;
            --glow: rgb(107 22 237 / 0.10);
            --text: #171717;
            --subtle: #737373;
            --faint: #d4d4d4;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --canvas: #0a0a0a;
                --glow: rgb(107 22 237 / 0.22);
                --text: #f5f5f5;
                --subtle: #a3a3a3;
                --faint: #2a2a2a;
            }
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px 24px;
            background: radial-gradient(ellipse 60% 45% at 50% 0%, var(--glow), transparent 70%), var(--canvas);
            color: var(--text);
            font: 15px/1.6 'Geist Sans', Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        main {
            width: 100%;
            max-width: 580px;
            text-align: center;
        }

        .status {
            margin: 0 0 20px;
            font: 600 88px/1 'Geist Mono', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            letter-spacing: -0.04em;
            background: linear-gradient(180deg, var(--text), var(--faint));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        h1 {
            margin: 0 0 12px;
            font-size: 24px;
            line-height: 1.3;
            font-weight: 600;
            letter-spacing: -0.01em;
        }

        p {
            margin: 0;
            color: var(--subtle);
        }

        #host {
            color: var(--text);
            font-weight: 500;
            word-break: break-all;
        }

        footer {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 56px;
            color: var(--subtle);
            font-size: 12px;
            opacity: 0.7;
        }

        footer a {
            color: inherit;
            text-decoration: underline;
            text-decoration-color: var(--faint);
            text-underline-offset: 3px;
        }

        footer a:hover {
            color: var(--text);
            text-decoration-color: currentColor;
        }

        footer svg {
            width: 14px;
            height: 14px;
            fill: currentColor;
        }
    </style>
</head>
<body>
    <main>
        @yield('content')
    </main>
    <footer>
        <svg viewBox="0 0 352 352" aria-hidden="true"><path d="M63.996 64V0h256v64Zm0 192h-64V64h64Zm0 0h256v64h-256Zm32-160V71.067h231.066V32h24.934v64zm0 0v152.533H71.063V96ZM56.93 263.066V288H31.997v-24.934ZM351.996 352h-256v-24.934h231.066V288h24.934z"/></svg>
        <span>Served by <a href="https://coolify.io" rel="noopener noreferrer">Coolify</a></span>
    </footer>
    <script>
        document.getElementById('host').textContent = location.hostname || 'This site';
    </script>
</body>
</html>
