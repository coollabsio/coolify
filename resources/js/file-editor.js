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
 * modal. It mirrors the Livewire `editorContent` string both ways: content is
 * loaded when a file is opened (`editorOpen` flips true) and pushed back to
 * Livewire on every edit (deferred, so it rides along with the save request).
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
                    content: docFor(this.$wire.get('editorContent'), this.$wire.get('editorLanguage')),
                    onUpdate: ({ editor }) => {
                        this.$wire.set('editorContent', editor.getText(), false);
                    },
                });

                // Reload content and language whenever a new file is opened.
                this.$wire.$watch('editorOpen', (open) => {
                    if (open && this.editor) {
                        this.editor.commands.setContent(
                            docFor(this.$wire.get('editorContent'), this.$wire.get('editorLanguage')),
                            false,
                        );
                        this.$nextTick(() => this.editor.commands.focus('end'));
                    }
                });
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
