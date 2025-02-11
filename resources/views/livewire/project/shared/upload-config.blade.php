<form wire:submit="uploadConfig" class="flex flex-col gap-2 w-full">
    <x-forms.button wire:click="setExampleConfig('dockerfile')">Pure Dockerfile</x-forms.button>
    <x-forms.button wire:click="setExampleConfig('dockerfile-without-coolify')">Pure Dockerfile without
        coolify</x-forms.button>
    <x-forms.button wire:click="setExampleConfig('git-dockerfile')">Git Dockerfile</x-forms.button>
    <x-forms.button wire:click="setExampleConfig('git-dockerfile-persistent-storage-scheduled-jobs')">Git Dockerfile with
        Persistent Storage & Scheduled Jobs</x-forms.button>
    {{-- <x-forms.button wire:click="setExampleConfig('dockercompose')">Docker Compose</x-forms.button>
    <x-forms.button wire:click="setExampleConfig('nixpacks')">Nixpacks</x-forms.button>
    <x-forms.button wire:click="setExampleConfig('static')">Static</x-forms.button> --}}
    <x-forms.textarea id="config" monacoEditorLanguage="json" useMonacoEditor />
    <x-forms.button type="submit">
        Upload
    </x-forms.button>
</form>
