<div wire:key="{{ rand() }}" class="coolify-monaco-editor flex-1">
    <div x-ref="monacoRef" x-data="{
        monacoVersion: '0.52.2',
        monacoContent: @entangle($id),
        monacoLanguage: '',
        monacoPlaceholder: true,
        monacoPlaceholderText: 'Start typing here',
        monacoLoader: true,
        monacoFontSize: '15px',
        monacoId: $id('monaco-editor'),
        isDarkMode() {
            return document.documentElement.classList.contains('dark') || localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches);
        },
        monacoEditor(editor) {
            editor.onDidChangeModelContent((e) => {
                this.monacoContent = editor.getValue();
                this.updatePlaceholder(editor.getValue());
            });
            editor.onDidBlurEditorWidget(() => {
                this.updatePlaceholder(editor.getValue());
            });
            editor.onDidFocusEditorWidget(() => {
                this.updatePlaceholder(editor.getValue());
            });
        },
        updatePlaceholder(value) {
            this.monacoPlaceholder = value === '';
        },
        monacoEditorFocus() {
            document.getElementById(this.monacoId).dispatchEvent(new CustomEvent('monaco-editor-focused', { detail: { monacoId: this.monacoId } }));
        },
        monacoEditorAddLoaderScriptToHead() {
            let script = document.createElement('script');
            script.src = `/js/monaco-editor-${this.monacoVersion}/min/vs/loader.js`;
            document.head.appendChild(script);
        }
    }" x-modelable="monacoContent">
        <div x-cloak x-init="if (typeof _amdLoaderGlobal == 'undefined') {
            monacoEditorAddLoaderScriptToHead();
        }
        checkTheme();
        let monacoLoaderInterval = setInterval(() => {
            if (typeof _amdLoaderGlobal !== 'undefined') {
                require.config({ paths: { 'vs': `/js/monaco-editor-${monacoVersion}/min/vs` } });
                let proxy = URL.createObjectURL(new Blob([`self.MonacoEnvironment={baseUrl:'${window.location.origin}/js/monaco-editor-${monacoVersion}/min'};importScripts('${window.location.origin}/js/monaco-editor-${monacoVersion}/min/vs/base/worker/workerMain.js');`], { type: 'text/javascript' }));
                window.MonacoEnvironment = { getWorkerUrl: () => proxy };
                require(['vs/editor/editor.main'], () => {
                    const editor = monaco.editor.create($refs.monacoEditorElement, {
                        value: monacoContent,
                        theme: document.documentElement.classList.contains('dark') ? 'vs-dark' : 'vs',
                        wordWrap: 'on',
                        readOnly: '{{ $readonly ?? false }}',
                        minimap: { enabled: false },
                        fontSize: monacoFontSize,
                        lineNumbersMinChars: 3,
                        automaticLayout: true,
                        language: '{{ $language }}',
                        domReadOnly: '{{ $readonly ?? false }}',
                        contextmenu: '!{{ $readonly ?? false }}',
                        renderLineHighlight: '{{ $readonly ?? false }} ? none : all',
                        stickyScroll: { enabled: false }
                    });
        
                    const observer = new MutationObserver((mutations) => {
                        mutations.forEach((mutation) => {
                            if (mutation.attributeName === 'class') {
                                const isDark = document.documentElement.classList.contains('dark');
                                monaco.editor.setTheme(isDark ? 'vs-dark' : 'vs');
                            }
                        });
                    });
        
                    observer.observe(document.documentElement, {
                        attributes: true,
                        attributeFilter: ['class']
                    });
        
                    monacoEditor(editor);
        
                    document.getElementById(monacoId).editor = editor;
                    document.getElementById(monacoId).addEventListener('monaco-editor-focused', (event) => {
                        editor.focus();
                    });
        
                    updatePlaceholder(editor.getValue());
        
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
            <div x-ref="monacoEditorElement" class="w-full h-96 text-md {{ $readonly ? 'opacity-65' : '' }}"></div>
            <div x-ref="monacoPlaceholderElement" x-show="monacoPlaceholder" @click="monacoEditorFocus()"
                :style="'font-size: ' + monacoFontSize"
                class="w-full text-sm font-mono absolute z-50 text-gray-500 ml-14 -translate-x-0.5 mt-0.5 left-0 top-0"
                x-text="monacoPlaceholderText"></div>
        </div>
    </div>
</div>
