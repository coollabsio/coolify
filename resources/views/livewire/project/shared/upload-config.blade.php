<form wire:submit="uploadConfig" class="flex flex-col gap-10 w-full">
    <x-forms.textarea  id="config" monacoEditorLanguage="json" useMonacoEditor />
    <x-forms.button type="submit">
        Upload
    </x-forms.button>
</form>
