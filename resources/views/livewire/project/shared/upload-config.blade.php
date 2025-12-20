<form wire:submit="uploadConfig" class="flex flex-col gap-2 w-full">
    <x-forms.textarea  id="config" monacoEditorLanguage="json" useMonacoEditor />
    <x-forms.button type="submit">
        {{ __('button.upload') }}
    </x-forms.button>
</form>
