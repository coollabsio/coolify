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
 * Register the `fileEditor` Alpine component used by the file browser's edit
 * modal. Content is pushed in via the server-dispatched `load-file-editor`
 * event (reliable across the teleported, wire:ignore'd modal) and synced back
 * to the Livewire `editorContent` property on every edit.
 */
export function initializeFileEditorComponent() {
    window.Alpine.data('fileEditor', function fileEditor() {
        return {
            editor: null,
            init() {
                this.editor = new Editor({
                    element: this.$refs.editor,
                    extensions: [CodeDocument, Text, CodeBlockLowlight.configure({ lowlight })],
                    editorProps: {
                        attributes: { class: 'file-editor-prosemirror', spellcheck: 'false' },
                    },
                    content: docFor('', null),
                    onUpdate: ({ editor }) => {
                        this.$wire.set('editorContent', editor.getText({ blockSeparator: '\n' }), false);
                    },
                });
            },
            load(detail) {
                if (!this.editor || !detail) {
                    return;
                }
                // v3 setContent(content, options) — options is an object, not a bool.
                this.editor.commands.setContent(docFor(detail.content ?? '', detail.language || null), {
                    emitUpdate: false,
                });
                this.$nextTick(() => this.editor.commands.focus('end'));
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
