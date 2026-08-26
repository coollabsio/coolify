import { Editor } from '@tiptap/core';
import Document from '@tiptap/extension-document';
import Text from '@tiptap/extension-text';
import CodeBlockLowlight from '@tiptap/extension-code-block-lowlight';
import { createLowlight, common } from 'lowlight';

const lowlight = createLowlight(common);

// The file editor is a single code block (no rich-text nodes) so the whole
// file is edited as code with syntax highlighting.
const CodeDocument = Document.extend({ content: 'codeBlock' });

function docFor(text, language) {
    return {
        type: 'doc',
        content: [
            {
                type: 'codeBlock',
                attrs: { language: language || null },
                content: text ? [{ type: 'text', text }] : [],
            },
        ],
    };
}

/**
 * Register the `fileEditor` Alpine component: a TipTap code editor dressed up
 * like an IDE with a line-number gutter and a Ln/Col status bar. The editor is
 * recreated fresh each time a file is loaded (content baked into the initial
 * doc) to avoid ProseMirror "mismatched transaction" errors from the teleported
 * wire:ignore'd modal. Edits sync back to the Livewire `editorContent` property.
 */
export function initializeFileEditorComponent() {
    window.Alpine.data('fileEditor', function fileEditor() {
        return {
            editor: null,
            language: 'plain text',
            lineCount: 0,
            activeLine: 0,

            create(text, language) {
                if (this.editor) {
                    this.editor.destroy();
                    this.editor = null;
                }
                // Clear any orphaned ProseMirror DOM left by a previous instance.
                this.$refs.editor.innerHTML = '';
                this.language = language || 'plain text';
                this.lineCount = 0;
                this.activeLine = 0;

                this.editor = new Editor({
                    element: this.$refs.editor,
                    extensions: [CodeDocument, Text, CodeBlockLowlight.configure({ lowlight })],
                    editorProps: {
                        attributes: { class: 'file-editor-prosemirror', spellcheck: 'false' },
                    },
                    content: docFor(text, language),
                    onUpdate: ({ editor }) => {
                        this.$wire.set('editorContent', editor.getText({ blockSeparator: '\n' }), false);
                        this.refresh();
                    },
                    onSelectionUpdate: () => this.refresh(),
                });

                this.refresh();
            },

            refresh() {
                if (!this.editor || !this.$refs.gutter) {
                    return;
                }
                const full = this.editor.getText({ blockSeparator: '\n' });
                const lines = full.length ? full.split('\n') : [''];

                // Rebuild the gutter only when the line count changes.
                if (lines.length !== this.lineCount) {
                    this.lineCount = lines.length;
                    this.$refs.gutter.innerHTML = Array.from(
                        { length: this.lineCount },
                        (unused, i) => `<div>${i + 1}</div>`,
                    ).join('');
                    this.activeLine = 0;
                }

                // Line/column from the current selection (offset by 1 for the
                // code block's opening position).
                const from = this.editor.state.selection.from;
                const offset = Math.max(0, from - 1);
                const before = full.slice(0, offset);
                const line = before.split('\n').length;
                const col = offset - before.lastIndexOf('\n');

                this.setActive(line);
                this.$refs.status.textContent = `Ln ${line}, Col ${col}`;
                this.$refs.statusRight.textContent = `${this.lineCount} ${this.lineCount === 1 ? 'line' : 'lines'} · ${this.language} · spaces`;
            },

            setActive(line) {
                if (line === this.activeLine) {
                    return;
                }
                const gutter = this.$refs.gutter;
                const prev = gutter.querySelector('.is-active');
                if (prev) {
                    prev.classList.remove('is-active');
                }
                const cur = gutter.children[line - 1];
                if (cur) {
                    cur.classList.add('is-active');
                }
                this.activeLine = line;
            },

            load(detail) {
                if (!detail) {
                    return;
                }
                this.create(detail.content ?? '', detail.language || null);
                this.$nextTick(() => this.editor && this.editor.commands.focus('end'));
            },

            destroy() {
                if (this.editor) {
                    this.editor.destroy();
                    this.editor = null;
                }
            },
        };
    });
}
