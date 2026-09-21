<div wire:key="{{ random_int(0, PHP_INT_MAX) }}" class="coolify-monaco-editor flex-1">
    <div x-ref="monacoRef" x-data="{
        monacoVersion: '0.52.2',
        monacoContent: @entangle($id),
        monacoLanguage: '',
        monacoLoader: true,
        monacoFontSize: '15px',
        monacoId: $id('monaco-editor'),
        isDarkMode() {
            return document.documentElement.classList.contains('dark') || localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches);
        },
        monacoEditor(editor) {
            editor.onDidChangeModelContent((e) => {
                this.monacoContent = editor.getValue();
            });
        },
        monacoEditorAddLoaderScriptToHead() {
            // Use a global flag to prevent duplicate script loading
            if (!window.__coolifyMonacoLoaderAdding && typeof _amdLoaderGlobal === 'undefined') {
                window.__coolifyMonacoLoaderAdding = true;
                let script = document.createElement('script');
                script.src = `/js/monaco-editor-${this.monacoVersion}/min/vs/loader.js`;
                script.onload = () => {
                    window.__coolifyMonacoLoaderAdding = false;
                };
                script.onerror = () => {
                    window.__coolifyMonacoLoaderAdding = false;
                };
                document.head.appendChild(script);
            }
        }
    }" x-modelable="monacoContent">
        <div x-cloak x-init="if (typeof _amdLoaderGlobal == 'undefined' && !window.__coolifyMonacoLoaderAdding) {
            monacoEditorAddLoaderScriptToHead();
        }
        checkTheme();
        let monacoLoaderInterval = setInterval(() => {
            if (typeof _amdLoaderGlobal !== 'undefined') {
                require.config({ paths: { 'vs': `/js/monaco-editor-${monacoVersion}/min/vs` } });
                let proxy = URL.createObjectURL(new Blob([`self.MonacoEnvironment={baseUrl:'${window.location.origin}/js/monaco-editor-${monacoVersion}/min'};importScripts('${window.location.origin}/js/monaco-editor-${monacoVersion}/min/vs/base/worker/workerMain.js');`], { type: 'text/javascript' }));
                window.MonacoEnvironment = { getWorkerUrl: () => proxy };
                require(['vs/editor/editor.main'], () => {
                    if (!window.__coolifyMonacoThemeDefined) {
                        monaco.editor.defineTheme('coolify-dark', {
                            base: 'vs-dark',
                            inherit: true,
                            rules: [],
                            colors: {
                                'editor.background': '#0b0b0c',
                                'editorGutter.background': '#0b0b0c',
                                'editorStickyScroll.background': '#0b0b0c',
                                'minimap.background': '#0b0b0c',
                                'scrollbarSlider.background': '#ffffff1a',
                                'scrollbarSlider.hoverBackground': '#ffffff2e',
                                'scrollbarSlider.activeBackground': '#ffffff40',
                                'scrollbar.shadow': '#00000000'
                            }
                        });
                        window.__coolifyMonacoThemeDefined = true;
                    }
                    @if ($language === 'nginx')
                    if (!monaco.languages.getLanguages().some((registered) => registered.id === 'nginx')) {
                        monaco.languages.register({ id: 'nginx' });
                        monaco.languages.setLanguageConfiguration('nginx', {
                            comments: { lineComment: '#' },
                            brackets: [['{', '}']],
                            autoClosingPairs: [
                                { open: '{', close: '}' },
                            ],
                        });
                        monaco.languages.setMonarchTokensProvider('nginx', {
                            defaultToken: '',
                            tokenizer: {
                                root: [
                                    [/#.*$/, 'comment'],
                                    [/\$[A-Za-z_]\w*/, 'variable'],
                                    [/^\s*(server|location|upstream|http|events|types|map|if|include|listen|server_name|root|index|alias|return|rewrite|try_files|proxy_pass|proxy_set_header|proxy_http_version|fastcgi_pass|fastcgi_param|add_header|expires|error_page|access_log|error_log|gzip|gzip_types|charset|sendfile|keepalive_timeout|client_max_body_size|default_type|worker_processes|worker_connections|ssl_certificate|ssl_certificate_key|allow|deny|autoindex|set|break|internal|limit_req|limit_conn|resolver)\b/, 'keyword'],
                                    [/^\s*[A-Za-z_][\w.]*/, 'type'],
                                    [/\u0022([^\u0022\\]|\\.)*\u0022/, 'string'],
                                    [/'([^'\\]|\\.)*'/, 'string'],
                                    [/\b\d+(\.\d+)?(ms|[smhdwMy]|[kKmMgG])?\b/, 'number'],
                                    [/[{}();]/, 'delimiter'],
                                    [/(~\*?|\^~|=|\!=)/, 'operator'],
                                ],
                            },
                        });
                    }
                    @endif
                    const editor = monaco.editor.create($refs.monacoEditorElement, {
                        value: monacoContent,
                        theme: document.documentElement.classList.contains('dark') ? 'coolify-dark' : 'vs',
                        wordWrap: 'on',
                        readOnly: '{{ $readonly ?? false }}',
                        minimap: { enabled: false },
                        fontSize: monacoFontSize,
                        lineNumbersMinChars: 3,
                        automaticLayout: true,
                        language: '{{ $language }}',
                        placeholder: 'Start typing here',
                        domReadOnly: '{{ $readonly ?? false }}',
                        contextmenu: '!{{ $readonly ?? false }}',
                        renderLineHighlight: 'none',
                        stickyScroll: { enabled: false },
                        padding: { top: 12, bottom: 12 },
                        overviewRulerLanes: 0,
                        overviewRulerBorder: false,
                        hideCursorInOverviewRuler: true,
                        scrollbar: {
                            vertical: 'auto',
                            horizontal: 'auto',
                            verticalScrollbarSize: 8,
                            horizontalScrollbarSize: 8,
                            useShadows: false
                        }
                    });
        
                    const observer = new MutationObserver((mutations) => {
                        mutations.forEach((mutation) => {
                            if (mutation.attributeName === 'class') {
                                const isDark = document.documentElement.classList.contains('dark');
                                monaco.editor.setTheme(isDark ? 'coolify-dark' : 'vs');
                            }
                        });
                    });
        
                    observer.observe(document.documentElement, {
                        attributes: true,
                        attributeFilter: ['class']
                    });
        
                    monacoEditor(editor);

                    document.getElementById(monacoId).editor = editor;

                    @if ($autofocus)
                    // Auto-focus the editor
                    setTimeout(() => editor.focus(), 100);
                    @endif
        
                    $watch('monacoContent', value => {
                        if (editor.getValue() !== value) {
                            editor.setValue(value);
                        }
                    });
        
        
                });
                clearInterval(monacoLoaderInterval);
                monacoLoader = false;
        
            }
        }, 5);" :id="monacoId">
        </div>
        <div class="relative z-10 w-full h-full">
            <div x-ref="monacoEditorElement" class="w-full text-md {{ $readonly ? 'opacity-65' : '' }}" style="height: var(--editor-height, calc(100vh - 20rem)); min-height: 150px;"></div>
        </div>
    </div>
</div>
