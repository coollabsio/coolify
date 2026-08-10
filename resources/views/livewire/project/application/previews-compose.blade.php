<form wire:submit="save"
    class="grid gap-3 rounded-lg bg-neutral-50 p-3 ring-1 ring-neutral-200 md:grid-cols-[minmax(0,1fr)_auto] md:items-end dark:bg-white/[0.025] dark:ring-white/[0.07]">
    <x-forms.input helper="One domain per preview." label="{{ Str::headline($serviceName) }} domain"
        id="domain" wire:change="save" canGate="update" :canResource="$preview->application" />
    <x-forms.button wire:click="generate">Generate domain</x-forms.button>
</form>
