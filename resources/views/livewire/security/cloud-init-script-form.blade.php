<form wire:submit="save" class="application-settings-form flex w-full flex-col gap-4">
    <x-forms.input id="name" label="Script name" helper="A recognizable name for this reusable script." required />
    <x-forms.textarea id="script" label="Script content" rows="12" monospace
        helper="Cloud-config YAML or another script accepted by your provider." required />
    <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
        <button type="submit"
            class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
            {{ $scriptId ? 'Update script' : 'Create script' }}
        </button>
    </div>
</form>
