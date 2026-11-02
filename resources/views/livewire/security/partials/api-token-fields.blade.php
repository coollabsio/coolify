{{--
    Fields of an ApiTokenForm. $form is the form property ('form' or 'editForm'). Everything that depends on
    the values reads them from $wire, because the browser fills the edit form without a request.
--}}
<div class="grid gap-4 lg:grid-cols-2">
    <x-forms.input required id="{{ $form }}.description" label="Description"
        placeholder="CI deployment token" />
    <x-forms.listbox id="{{ $form }}.expiresInDays" label="Expires in" :options="$expiryOptions"
        :x-effect="$form === 'editForm' ? 'options[0].label = editingToken?.keepLabel ?? options[0].label' : null" />
</div>

<div class="mt-4 border-t border-neutral-200 pt-3 dark:border-white/[0.08]">
    <div class="mb-2 flex items-center gap-2">
        <h4 class="text-[12px] font-semibold text-black dark:text-fg">Permissions</h4>
        <x-helper helper="Only grant the abilities this token needs." />
    </div>
    {{-- A fixed panel escapes the scrolling modal body, so it is not cut off. --}}
    <div class="relative" x-data="{
        permissionsOpen: false,
        panelPosition: {},
        positionPanel() {
            const gap = 4;
            const edge = 12;
            const triggerRect = this.$refs.permissionsTrigger.getBoundingClientRect();
            const panelHeight = Math.min(this.$refs.permissionsPanel.scrollHeight, 256);
            const fitsBelow = window.innerHeight - triggerRect.bottom - gap >= panelHeight;
            this.panelPosition = {
                top: `${fitsBelow ? triggerRect.bottom + gap : Math.max(edge, triggerRect.top - gap - panelHeight)}px`,
                left: `${triggerRect.left}px`,
                width: `${triggerRect.width}px`,
                minWidth: `${triggerRect.width}px`,
                maxWidth: `${triggerRect.width}px`,
            };
        },
        get isRoot() {
            return ($wire.{{ $form }}.permissions ?? []).includes('root');
        },
        get summary() {
            return ($wire.{{ $form }}.permissions ?? [])
                .map((permission) => permission.split(':').map((word) => word.charAt(0).toUpperCase() + word.slice(1)).join(' '))
                .join(', ');
        },
    }" @click.outside="permissionsOpen = false" @keydown.escape.window="permissionsOpen = false"
        @resize.window="permissionsOpen && positionPanel()" @scroll.window.capture="permissionsOpen && positionPanel()">
        <button type="button" x-ref="permissionsTrigger" class="listbox-trigger" @click="permissionsOpen = !permissionsOpen"
            aria-haspopup="listbox" :aria-expanded="permissionsOpen">
            <span class="truncate" x-text="'Selected permissions: ' + summary"></span>
            <x-reicon name="chevron-down" class="size-3.5 shrink-0 opacity-60" />
        </button>

        <div x-cloak x-show="permissionsOpen" x-ref="permissionsPanel" x-transition.origin.top
            x-effect="if (permissionsOpen) $nextTick(() => positionPanel())"
            class="listbox-panel" style="position: fixed; z-index: 9999" :style="panelPosition" role="listbox">
            <div class="listbox-option p-0!">
                <x-forms.checkbox id="permission-root" label="Root" fullWidth
                    wire:model.live="{{ $form }}.permissions" domValue="root"
                    helper="Full access to every API operation."
                    :disabled="!$canUseRootPermissions" />
            </div>
            <div class="listbox-option p-0!">
                <x-forms.checkbox id="permission-write" label="Write" fullWidth
                    wire:model.live="{{ $form }}.permissions" domValue="write"
                    helper="Create and update resources."
                    :disabled="!$canUseWritePermissions"
                    x-bind:disabled="isRoot || {{ $canUseWritePermissions ? 'false' : 'true' }}" />
            </div>
            <div class="listbox-option p-0!">
                <x-forms.checkbox id="permission-delete" label="Delete" fullWidth
                    wire:model.live="{{ $form }}.permissions" domValue="delete"
                    helper="Delete resources and their settings (every DELETE request)."
                    :disabled="!$canUseDeletePermissions"
                    x-bind:disabled="isRoot || {{ $canUseDeletePermissions ? 'false' : 'true' }}" />
            </div>
            <div class="listbox-option p-0!">
                <x-forms.checkbox id="permission-deploy" label="Deploy" fullWidth
                    wire:model.live="{{ $form }}.permissions" domValue="deploy"
                    helper="Deploy, start, stop, and restart resources. With Write, also apply changes that run on servers immediately, such as scheduled tasks, proxy configuration, and imports."
                    :disabled="!$canUseDeployPermissions"
                    x-bind:disabled="isRoot || {{ $canUseDeployPermissions ? 'false' : 'true' }}" />
            </div>
            <div class="listbox-option p-0!">
                <x-forms.checkbox id="permission-read" label="Read" fullWidth
                    wire:model.live="{{ $form }}.permissions" domValue="read"
                    helper="Read non-sensitive resource data."
                    x-bind:disabled="isRoot" />
            </div>
            <div class="listbox-option p-0!">
                <x-forms.checkbox id="permission-read-sensitive" label="Read sensitive data"
                    fullWidth wire:model.live="{{ $form }}.permissions" domValue="read:sensitive"
                    helper="Include secrets, logs, passwords, and Compose content."
                    :disabled="!$canUseSensitivePermissions"
                    x-bind:disabled="isRoot || {{ $canUseSensitivePermissions ? 'false' : 'true' }}" />
            </div>
        </div>
    </div>
</div>

<div class="mt-4 border-t border-neutral-200 pt-3 dark:border-white/[0.08]" x-data="{
    labels: @js($scopeLabels),
    types: ['project', 'environment', 'resource'],
    get isSelectedMode() {
        return $wire.{{ $form }}.scopeMode === 'selected';
    },
    get sorted() {
        return [...($wire.{{ $form }}.selectedScopes ?? [])].sort((a, b) =>
            this.types.indexOf(a.split(':')[0]) - this.types.indexOf(b.split(':')[0])
            || (this.labels[a] ?? a).localeCompare(this.labels[b] ?? b));
    },
    {{-- A selected project already covers its environments and resources, and a selected environment its resources. --}}
    coverScopes(listbox) {
        const selected = listbox.selected;
        const parentsOf = Object.fromEntries(listbox.options.map((option) => [option.value, option.parents ?? []]));
        const coveredBy = (value) => (parentsOf[value] ?? []).find((parent) => selected.includes(parent));
        listbox.options.forEach((option) => {
            const parent = coveredBy(option.value);
            option.disabled = Boolean(parent);
            option.description = parent ? `Included in ${this.labels[parent]}` : null;
        });
        if (selected.some(coveredBy)) {
            {{-- Set both sides of the entangle: a change on one side in the same tick as the click is lost. --}}
            const kept = selected.filter((value) => !coveredBy(value));
            listbox.value = kept;
            $wire.{{ $form }}.selectedScopes = [...kept];
        }
    },
}">
    <div class="mb-2 flex items-center gap-2">
        <h4 class="text-[12px] font-semibold text-black dark:text-fg">Access</h4>
        <x-helper helper="A limited token sees only the selected projects, environments, and resources, and cannot use team-level endpoints such as servers, keys, or notifications." />
    </div>
    <div class="grid gap-4 lg:grid-cols-2">
        <x-forms.listbox id="{{ $form }}.scopeMode" label="Resources" :options="[
            ['value' => 'team', 'label' => 'All resources in the team', 'disabled' => !$canUseTeamScope],
            ['value' => 'selected', 'label' => 'Selected projects, environments, or resources'],
        ]" />
        <div class="min-w-0">
            <x-forms.listbox id="{{ $form }}.selectedScopes" label="Selected" multiple searchable :portal="! $inModal" countLabel="resource"
                x-bind:disabled="!isSelectedMode"
                placeholder="Choose projects, environments, or resources"
                emptyText="No projects or resources available."
                :options="$this->scopeOptions" x-effect="coverScopes($data)" />
        </div>
    </div>
    <div x-show="isSelectedMode && sorted.length > 0"
        class="mt-3 divide-y divide-neutral-200 rounded-md border border-neutral-200 dark:divide-white/[0.06] dark:border-white/[0.08]">
        <template x-for="scope in sorted" :key="scope">
            <div class="flex min-h-8 items-center gap-3 py-1 pl-3 pr-1.5 text-[12px]">
                <span class="w-20 shrink-0 text-[11px] capitalize text-neutral-500 dark:text-fg-dim"
                    x-text="scope.split(':')[0]"></span>
                <span class="min-w-0 flex-1 truncate text-black dark:text-fg"
                    x-text="labels[scope] ?? scope"></span>
                <button type="button" class="icon-button shrink-0" title="Remove"
                    :aria-label="'Remove ' + (labels[scope] ?? scope)"
                    @click="$wire.{{ $form }}.selectedScopes = $wire.{{ $form }}.selectedScopes.filter((value) => value !== scope)">
                    <x-reicon name="x" class="size-3.5" />
                </button>
            </div>
        </template>
    </div>
</div>
