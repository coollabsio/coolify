<form wire:submit="uploadConfig" class="flex flex-col gap-2 w-full">
    <x-forms.textarea id="config" monacoEditorLanguage="json" monacoJsonSchema="/schemas/coolify.schema.json"
        useMonacoEditor />
    <x-forms.button type="submit">
        Upload
    </x-forms.button>
</form>
