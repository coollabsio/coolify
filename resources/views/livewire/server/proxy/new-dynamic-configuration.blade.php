<form wire:submit.prevent="addDynamicConfiguration" class="flex flex-col w-full gap-4">
    <x-forms.input canGate="update" :canResource="$server" id="fileName" label="Filename" required />
    <x-forms.textarea canGate="update" :canResource="$server" allowTab useMonacoEditor
        monacoEditorLanguage="{{ $server->proxyType() === \App\Enums\ProxyTypes::TRAEFIK->value ? 'yaml' : '' }}" id="value" label="Configuration"
        required rows="20" />
    <x-forms.button canGate="update" :canResource="$server" type="submit" @click="slideOverOpen=false">Save</x-forms.button>
</form>
