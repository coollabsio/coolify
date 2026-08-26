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
 * modal. The editor is recreated fresh each time a file is loaded (content
 * baked into the initial doc) rather than mutated via setContent — that avoids
 * ProseMirror "mismatched transaction" errors caused by the teleported,
 * wire:ignore'd modal churning editor instances. Edits sync back to the
 * Livewire `editorContent` property.
 */
export function initializeFileEditorComponent() {
    window.Alpine.data('fileEditor', function fileEditor() {
        return {
            editor: null,
            create(text, language) {
                if (this.editor) {
                    this.editor.destroy();
                    this.editor = null;
                }
                // Clear any orphaned ProseMirror DOM left by a previous instance.
                this.$refs.editor.innerHTML = '';
                this.editor = new Editor({
                    element: this.$refs.editor,
                    extensions: [CodeDocument, Text, CodeBlockLowlight.configure({ lowlight })],
                    editorProps: {
                        attributes: { class: 'file-editor-prosemirror', spellcheck: 'false' },
                    },
                    content: docFor(text, language),
                    onUpdate: ({ editor }) => {
                        this.$wire.set('editorContent', editor.getText({ blockSeparator: '\n' }), false);
                    },
                });
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
