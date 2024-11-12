<div wire:key="{{ rand() }}" class="coolify-monaco-editor flex-1">
    <div x-ref="monacoRef" x-data="{
        monacoContent: @entangle($id),
        monacoLanguage: '',
        monacoPlaceholder: true,
        monacoPlaceholderText: 'Start typing here',
        monacoLoader: true,
        monacoFontSize: '15px',
        monacoId: $id('monaco-editor'),
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
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.39.0/min/vs/loader.min.js';
            document.head.appendChild(script);
        }
    }" x-modelable="monacoContent">
        <div x-cloak x-init="if (typeof _amdLoaderGlobal == 'undefined') {
            monacoEditorAddLoaderScriptToHead();
        }
        checkTheme();
        let monacoLoaderInterval = setInterval(() => {
            if (typeof _amdLoaderGlobal !== 'undefined') {
                require.config({ paths: { 'vs': 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.39.0/min/vs' } });
                let proxy = URL.createObjectURL(new Blob([` self.MonacoEnvironment = { baseUrl: 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.39.0/min' }; importScripts('https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.39.0/min/vs/base/worker/workerMain.min.js');`], { type: 'text/javascript' }));
                window.MonacoEnvironment = { getWorkerUrl: () => proxy };
                require(['vs/editor/editor.main'], () => {
                    monaco.editor.defineTheme('blackboard', {
                        'base': 'vs-dark',
                        'inherit': true,
                        'rules': [{
                                'background': editorBackground,
                                'token': ''
                            },
                            {
                                'foreground': '959da5',
                                'token': 'comment'
                            },
                            {
                                'foreground': '959da5',
                                'token': 'punctuation.definition.comment'
                            },
                            {
                                'foreground': '959da5',
                                'token': 'string.comment'
                            },
                            {
                                'foreground': 'c8e1ff',
                                'token': 'constant'
                            },
                            {
                                'foreground': 'c8e1ff',
                                'token': 'entity.name.constant'
                            },
                            {
                                'foreground': 'c8e1ff',
                                'token': 'variable.other.constant'
                            },
                            {
                                'foreground': 'c8e1ff',
                                'token': 'variable.language'
                            },
                            {
                                'foreground': 'b392f0',
                                'token': 'entity'
                            },
                            {
                                'foreground': 'b392f0',
                                'token': 'entity.name'
                            },
                            {
                                'foreground': 'f6f8fa',
                                'token': 'variable.parameter.function'
                            },
                            {
                                'foreground': '7bcc72',
                                'token': 'entity.name.tag'
                            },
                            {
                                'foreground': 'ea4a5a',
                                'token': 'keyword'
                            },
                            {
                                'foreground': 'ea4a5a',
                                'token': 'storage'
                            },
                            {
                                'foreground': 'ea4a5a',
                                'token': 'storage.type'
                            },
                            {
                                'foreground': 'f6f8fa',
                                'token': 'storage.modifier.package'
                            },
                            {
                                'foreground': 'f6f8fa',
                                'token': 'storage.modifier.import'
                            },
                            {
                                'foreground': 'f6f8fa',
                                'token': 'storage.type.java'
                            },
                            {
                                'foreground': '79b8ff',
                                'token': 'string'
                            },
                            {
                                'foreground': '79b8ff',
                                'token': 'punctuation.definition.string'
                            },
                            {
                                'foreground': '79b8ff',
                                'token': 'string punctuation.section.embedded source'
                            },
                            {
                                'foreground': 'c8e1ff',
                                'token': 'support'
                            },
                            {
                                'foreground': 'c8e1ff',
                                'token': 'meta.property-name'
                            },
                            {
                                'foreground': 'fb8532',
                                'token': 'variable'
                            },
                            {
                                'foreground': 'f6f8fa',
                                'token': 'variable.other'
                            },
                            {
                                'foreground': 'd73a49',
                                'fontStyle': 'bold italic underline',
                                'token': 'invalid.broken'
                            },
                            {
                                'foreground': 'd73a49',
                                'fontStyle': 'bold italic underline',
                                'token': 'invalid.deprecated'
                            },
                            {
                                'foreground': 'fafbfc',
                                'background': 'd73a49',
                                'fontStyle': 'italic underline',
                                'token': 'invalid.illegal'
                            },
                            {
                                'foreground': 'fafbfc',
                                'background': 'd73a49',
                                'fontStyle': 'italic underline',
                                'token': 'carriage-return'
                            },
                            {
                                'foreground': 'd73a49',
                                'fontStyle': 'bold italic underline',
                                'token': 'invalid.unimplemented'
                            },
                            {
                                'foreground': 'd73a49',
                                'token': 'message.error'
                            },
                            {
                                'foreground': 'f6f8fa',
                                'token': 'string source'
                            },
                            {
                                'foreground': 'c8e1ff',
                                'token': 'string variable'
                            },
                            {
                                'foreground': '79b8ff',
                                'token': 'source.regexp'
                            },
                            {
                                'foreground': '79b8ff',
                                'token': 'string.regexp'
                            },
                            {
                                'foreground': '79b8ff',
                                'token': 'string.regexp.character-class'
                            },
                            {
                                'foreground': '79b8ff',
                                'token': 'string.regexp constant.character.escape'
                            },
                            {
                                'foreground': '79b8ff',
                                'token': 'string.regexp source.ruby.embedded'
                            },
                            {
                                'foreground': '79b8ff',
                                'token': 'string.regexp string.regexp.arbitrary-repitition'
                            },
                            {
                                'foreground': '7bcc72',
                                'fontStyle': 'bold',
                                'token': 'string.regexp constant.character.escape'
                            },
                            {
                                'foreground': 'c8e1ff',
                                'token': 'support.constant'
                            },
                            {
                                'foreground': 'c8e1ff',
                                'token': 'support.variable'
                            },
                            {
                                'foreground': 'c8e1ff',
                                'token': 'meta.module-reference'
                            },
                            {
                                'foreground': 'fb8532',
                                'token': 'markup.list'
                            },
                            {
                                'foreground': '0366d6',
                                'fontStyle': 'bold',
                                'token': 'markup.heading'
                            },
                            {
                                'foreground': '0366d6',
                                'fontStyle': 'bold',
                                'token': 'markup.heading entity.name'
                            },
                            {
                                'foreground': 'c8e1ff',
                                'token': 'markup.quote'
                            },
                            {
                                'foreground': 'f6f8fa',
                                'fontStyle': 'italic',
                                'token': 'markup.italic'
                            },
                            {
                                'foreground': 'f6f8fa',
                                'fontStyle': 'bold',
                                'token': 'markup.bold'
                            },
                            {
                                'foreground': 'c8e1ff',
                                'token': 'markup.raw'
                            },
                            {
                                'foreground': 'b31d28',
                                'background': 'ffeef0',
                                'token': 'markup.deleted'
                            },
                            {
                                'foreground': 'b31d28',
                                'background': 'ffeef0',
                                'token': 'meta.diff.header.from-file'
                            },
                            {
                                'foreground': 'b31d28',
                                'background': 'ffeef0',
                                'token': 'punctuation.definition.deleted'
                            },
                            {
                                'foreground': '176f2c',
                                'background': 'f0fff4',
                                'token': 'markup.inserted'
                            },
                            {
                                'foreground': '176f2c',
                                'background': 'f0fff4',
                                'token': 'meta.diff.header.to-file'
                            },
                            {
                                'foreground': '176f2c',
                                'background': 'f0fff4',
                                'token': 'punctuation.definition.inserted'
                            },
                            {
                                'foreground': 'b08800',
                                'background': 'fffdef',
                                'token': 'markup.changed'
                            },
                            {
                                'foreground': 'b08800',
                                'background': 'fffdef',
                                'token': 'punctuation.definition.changed'
                            },
                            {
                                'foreground': '2f363d',
                                'background': '959da5',
                                'token': 'markup.ignored'
                            },
                            {
                                'foreground': '2f363d',
                                'background': '959da5',
                                'token': 'markup.untracked'
                            },
                            {
                                'foreground': 'b392f0',
                                'fontStyle': 'bold',
                                'token': 'meta.diff.range'
                            },
                            {
                                'foreground': 'c8e1ff',
                                'token': 'meta.diff.header'
                            },
                            {
                                'foreground': '0366d6',
                                'fontStyle': 'bold',
                                'token': 'meta.separator'
                            },
                            {
                                'foreground': '0366d6',
                                'token': 'meta.output'
                            },
                            {
                                'foreground': 'ffeef0',
                                'token': 'brackethighlighter.tag'
                            },
                            {
                                'foreground': 'ffeef0',
                                'token': 'brackethighlighter.curly'
                            },
                            {
                                'foreground': 'ffeef0',
                                'token': 'brackethighlighter.round'
                            },
                            {
                                'foreground': 'ffeef0',
                                'token': 'brackethighlighter.square'
                            },
                            {
                                'foreground': 'ffeef0',
                                'token': 'brackethighlighter.angle'
                            },
                            {
                                'foreground': 'ffeef0',
                                'token': 'brackethighlighter.quote'
                            },
                            {
                                'foreground': 'd73a49',
                                'token': 'brackethighlighter.unmatched'
                            },
                            {
                                'foreground': 'd73a49',
                                'token': 'sublimelinter.mark.error'
                            },
                            {
                                'foreground': 'fb8532',
                                'token': 'sublimelinter.mark.warning'
                            },
                            {
                                'foreground': '6a737d',
                                'token': 'sublimelinter.gutter-mark'
                            },
                            {
                                'foreground': '79b8ff',
                                'fontStyle': 'underline',
                                'token': 'constant.other.reference.link'
                            },
                            {
                                'foreground': '79b8ff',
                                'fontStyle': 'underline',
                                'token': 'string.other.link'
                            }
                        ],
                        'colors': {
                            'editor.foreground': '#f6f8fa',
                            'editor.background': editorBackground,
                            'editor.selectionBackground': '#4c2889',
                            'editor.inactiveSelectionBackground': '#444d56',
                            'editor.lineHighlightBackground': '#444d56',
                            'editorCursor.foreground': '#ffffff',
                            'editorWhitespace.foreground': '#6a737d',
                            'editorIndentGuide.background': '#6a737d',
                            'editorIndentGuide.activeBackground': '#f6f8fa',
                            'editor.selectionHighlightBorder': '#444d56'
                        }
                    });

                    const editor = monaco.editor.create($refs.monacoEditorElement, {
                        value: monacoContent,
                        theme: editorTheme,
                        wordWrap: 'on',
                        readOnly: '{{ $readonly ?? false }}',
                        minimap: { enabled: false },
                        fontSize: monacoFontSize,
                        lineNumbersMinChars: 3,
                        automaticLayout: true,
                        language: '{{ $language }}'

                    });

                    monacoEditor(editor);

                    document.getElementById(monacoId).editor = editor;
                    document.getElementById(monacoId).addEventListener('monaco-editor-focused', (event) => {
                        editor.focus();
                    });

                    updatePlaceholder(editor.getValue());

                    // Watch for changes in monacoContent from Livewire
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
            <div x-ref="monacoEditorElement" class="w-full h-96 text-md"></div>
            <div x-ref="monacoPlaceholderElement" x-show="monacoPlaceholder" @click="monacoEditorFocus()"
                :style="'font-size: ' + monacoFontSize"
                class="w-full text-sm font-mono absolute z-50 text-gray-500 ml-14 -translate-x-0.5 mt-0.5 left-0 top-0"
                x-text="monacoPlaceholderText"></div>
        </div>
    </div>
</div>
