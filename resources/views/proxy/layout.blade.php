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
            --card: #ffffff;
            --hairline: #d4d4d4;
            --text: #171717;
            --subtle: #737373;
            --accent: #6b16ed;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --canvas: #0a0a0a;
                --card: #1c1c1c;
                --hairline: #333333;
                --text: #f5f5f5;
                --subtle: #a3a3a3;
                --accent: #fcd452;
            }
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: var(--canvas);
            color: var(--text);
            font: 14px/1.6 'Geist Sans', Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        main {
            width: 100%;
            max-width: 560px;
            padding: 32px;
            background: var(--card);
            border-radius: 12px;
            box-shadow: 0 0 0 1px var(--hairline), 0 1px 2px rgb(0 0 0 / 0.05);
        }

        .status {
            margin: 0 0 8px;
            color: var(--subtle);
            font: 12px/1.4 'Geist Mono', ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 20px;
            line-height: 1.3;
            font-weight: 600;
        }

        h2 {
            margin: 0 0 8px;
            font-size: 14px;
            font-weight: 600;
        }

        p {
            margin: 0;
            color: var(--subtle);
        }

        section {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--hairline);
        }

        ul {
            margin: 0 0 16px;
            padding-left: 20px;
            color: var(--subtle);
        }

        li + li {
            margin-top: 4px;
        }

        a {
            color: var(--accent);
            font-weight: 500;
        }

        #host {
            color: var(--text);
            font-weight: 500;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <main>
        @yield('content')
    </main>
    <script>
        document.getElementById('host').textContent = location.hostname || 'This site';
    </script>
</body>
</html>
