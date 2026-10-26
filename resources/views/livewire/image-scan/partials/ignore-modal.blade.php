{{-- One modal for every Ignore button. Rows open it with the open-ignore-modal window event. --}}
<div x-data="{
    target: '',
    kind: 'vulnerability',
    owner: null,
    scope: 'resource',
    expires: '90',
    reason: '',
    saving: false,
    open(detail) {
        this.target = detail.target;
        this.kind = detail.kind;
        this.owner = detail.owner ?? null;
        this.scope = 'resource';
        this.expires = '90';
        this.reason = '';
    },
    async submit() {
        if (this.saving || this.reason.trim() === '') return;
        this.saving = true;
        try {
            {{-- Validation errors arrive as an error toast; the modal stays open so the input can be fixed. --}}
            const saved = await $wire.ignore(this.target, this.kind, this.scope, this.expires, this.reason, this.owner);
            if (saved === true) $dispatch('close-modal');
        } catch (error) {
            window.toast?.('Could not ignore', { type: 'danger', description: 'You may not have permission to do this. Reload the page and try again.' });
        } finally {
            this.saving = false;
        }
    },
}" x-on:open-ignore-modal.window="open($event.detail)">
    @php
        $ignoreTitle = new \Illuminate\Support\HtmlString(
            '<span x-text="kind === \'package\' ? \'Ignore package\' : \'Ignore vulnerability\'">Ignore vulnerability</span>'
        );
    @endphp
    <x-modal-input :title="$ignoreTitle" x-on:open-ignore-modal.window="modalOpen = true">
        <x-slot:content></x-slot:content>
        <form class="flex flex-col gap-4" x-on:submit.prevent="submit()">
            <p class="break-all font-mono text-[12px] text-neutral-600 dark:text-fg-dim" x-text="target"></p>
            <div class="flex-1 form-control">
                <label for="image-scan-ignore-reason" class="mb-1.5 flex items-center gap-1 text-sm font-medium leading-4">
                    Reason <x-highlighted text="*" />
                </label>
                <textarea id="image-scan-ignore-reason" x-model="reason" rows="3" maxlength="500" required
                    class="input scrollbar" placeholder="Why this does not affect the app"></textarea>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-forms.listbox id="image-scan-ignore-scope" label="Scope" :wire="false" x-model="scope" value="resource"
                    :options="array_values(array_filter([
                        ['value' => 'resource', 'label' => 'This resource'],
                        $canIgnoreForTeam ? ['value' => 'team', 'label' => 'All team resources'] : null,
                    ]))" />
                <x-forms.listbox id="image-scan-ignore-expires" label="Expires" :wire="false" x-model="expires" value="90"
                    :options="[
                        ['value' => '30', 'label' => '30 days'],
                        ['value' => '90', 'label' => '90 days'],
                        ['value' => 'never', 'label' => 'Never'],
                    ]" />
            </div>
        </form>
        <x-slot:footer>
            <x-forms.button class="normal-case!" type="button" x-on:click="$dispatch('close-modal')">Cancel</x-forms.button>
            <x-forms.button class="normal-case!" type="button" isHighlighted x-on:click="submit()"
                x-bind:disabled="saving || reason.trim() === ''">Ignore</x-forms.button>
        </x-slot:footer>
    </x-modal-input>
</div>
