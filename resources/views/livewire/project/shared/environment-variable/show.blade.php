@php
    $rowScope = $isSharedVariable ? 'shared' : (data_get($env, 'is_preview') ? 'preview' : 'production');
    $rowScopeLabel = $isSharedVariable ? str($type)->headline() : ($rowScope === 'preview' ? 'Preview' : 'Production');
    $canUpdate = auth()->user()?->can('update', $this->env) ?? false;
    $canEditValue = $canUpdate && !$isLocked && !$isDisabled && !$isValueHidden;
    $showValueType = !$is_redis_credential && !$isMagicVariable;
    $showInterpolation = !$is_redis_credential && !$isMagicVariable && !$isSharedVariable;
    $showBuildtime = !$is_redis_credential && !$isMagicVariable && !$isSharedVariable;
    $showRuntime = !$is_redis_credential && !$isMagicVariable && !$isSharedVariable;
@endphp
<div class="env-table-item"
    @if ($isSharedVariable) :style="`order: ${sharedSort === 'alphabetical' ? {{ $tableAlphabeticalOrder }} : {{ $tableCreationOrder }}}`" @endif
    x-show="(typeof envFilter === 'undefined' || envFilter === 'all' || envFilter === '{{ $rowScope }}')
        && (typeof sharedSearch === 'undefined' || @js(mb_strtolower($env->key . ' ' . ($comment ?? '') . ' ' . $rowScopeLabel)).includes(sharedSearch.trim().toLowerCase()))">
    <div class="data-table-row {{ $isSharedVariable ? 'env-table-grid-shared' : 'env-table-grid' }} {{ ! $isSharedVariable && ! $showEnvironmentType ? 'env-table-grid-no-type' : '' }}">
        <div class="flex min-w-0 items-center gap-2">
            @if ($isLocked)
                <svg class="size-3.5 shrink-0 text-neutral-400 dark:text-fg-faint" viewBox="0 0 24 24"
                    xmlns="http://www.w3.org/2000/svg">
                    <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                        stroke-width="2">
                        <path d="M5 13a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6z" />
                        <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0-2 0m-3-5V7a4 4 0 1 1 8 0v4" />
                    </g>
                </svg>
            @endif
            <button type="button" data-env-name-trigger
                class="env-key-label min-w-0 truncate text-left font-mono text-[13px] text-black dark:text-fg"
                title="{{ $env->key }}"
                @click="$el.closest('.data-table-row').querySelector('[data-env-settings-trigger]').click()">
                {{ $env->key }}
            </button>
            @if (! $isSharedVariable && filled($comment))
                <x-helper :helper="e($comment)" />
            @endif
            @if ($is_really_required)
                <span class="table-badge table-badge-danger shrink-0">Required</span>
            @endif
        </div>
        @if (! $isSharedVariable)
            @if ($isMagicVariable)
                <span class="env-managed-desktop data-table-cell-check">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                        stroke="currentColor" class="size-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                </span>
            @else
                <span class="env-managed-desktop data-table-cell-dash">-</span>
            @endif
        @endif
        @if ($showEnvironmentType)
            <div class="env-type-desktop text-[13px] text-neutral-500 dark:text-fg-dim">{{ $rowScopeLabel }}</div>
        @endif
        @if ($isSharedVariable)
            <div class="min-w-0 truncate text-[13px] text-neutral-500 dark:text-fg-dim"
                @if ($comment) title="{{ $comment }}" @endif>
                {{ $comment ?: '-' }}
            </div>
        @endif
        @if ($isSharedVariable)
            @if ($is_multiline)
                <span class="data-table-cell-check">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                        stroke="currentColor" class="size-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                </span>
            @else
                <span class="data-table-cell-dash">-</span>
            @endif
        @else
            @foreach ([$is_literal, $is_multiline, $is_buildtime && !$is_redis_credential, $is_runtime && !$is_redis_credential] as $flag)
                @if ($flag)
                    <span class="data-table-cell-check">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                            stroke="currentColor" class="size-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    </span>
                @else
                    <span class="data-table-cell-dash">-</span>
                @endif
            @endforeach
        @endif
        <div class="flex items-center gap-0.5 justify-self-end">
            @if (! $isLocked && ! $isValueHidden)
                <x-copy-button resolve="$wire.copyValue()" label="Copy value" />
            @endif
            {{-- Open modal immediately (Alpine); decrypt value in a follow-up Livewire request. --}}
            <x-modal-input title="Edit environment variable" :closeOutside="false" :wireIgnore="false"
                wireOpen="editorOpen">
                <x-slot:content>
                    <button type="button" wire:click="loadValues" data-env-settings-trigger class="icon-button shrink-0"
                        title="Edit environment variable" aria-label="Edit environment variable">
                        <x-reicon name="settings" class="size-3.5" />
                    </button>
                </x-slot:content>

                <form wire:submit="submit" class="flex w-full flex-col gap-4"
                    x-data="{ isMultiline: $wire.entangle('is_multiline') }">
                    <div class="grid items-end gap-4 sm:grid-cols-2">
                        <x-forms.input id="key" label="Name" :required="$is_redis_credential"
                            :disabled="!$canEditValue || $is_redis_credential" />
                        <x-forms.input id="comment" label="Comment" placeholder="Optional note"
                            helper="Add a note to document what this environment variable is used for." maxlength="256"
                            :disabled="!$canUpdate" />
                    </div>

                    <div>
                        @if ($isValueHidden)
                            <div class="w-full">
                                <label class="mb-1 flex items-center gap-1 text-sm font-medium">Value</label>
                                <input disabled type="text" value="Hidden (only admins can view)"
                                    class="input w-full italic !text-neutral-500 dark:!text-neutral-500" />
                            </div>
                        @elseif ($isLocked)
                            <div class="w-full">
                                <label class="mb-1 flex items-center gap-1 text-sm font-medium">Value</label>
                                <input disabled type="text" value="Hidden after locking"
                                    class="input w-full italic !text-neutral-500 dark:!text-neutral-500" />
                            </div>
                        @else
                            {{-- Multiline: same shell while loading/loaded to avoid layout jump. --}}
                            <div x-show="isMultiline" x-cloak wire:key="env-show-value-multiline-{{ $env->id }}"
                                class="w-full">
                                <label class="mb-1 flex items-center gap-1 text-sm font-medium">
                                    Value
                                    @if ($is_redis_credential)
                                        <x-highlighted text="*" />
                                    @endif
                                </label>
                                @if (!$valuesLoaded)
                                    <div class="input flex min-h-24 w-full items-start py-2 text-neutral-500 dark:text-fg-dim"
                                        aria-busy="true">
                                        <x-loading text="Loading value..." />
                                    </div>
                                @else
                                    <x-forms.textarea id="value" class="font-sans" :required="$is_redis_credential"
                                        :disabled="!$canEditValue" spellcheck />
                                @endif
                            </div>

                            {{-- Single-line: keep one label + control shell so label/input gap never changes. --}}
                            <div x-show="!isMultiline" class="w-full"
                                wire:key="env-show-value-single-{{ $env->id }}">
                                <label class="mb-1 flex items-center gap-1 text-sm font-medium">
                                    Value
                                    @if ($is_redis_credential)
                                        <x-highlighted text="*" />
                                    @endif
                                </label>
                                <div class="relative">
                                    @if (!$valuesLoaded)
                                        <x-forms.input loading loadingText="Loading value..."
                                            defaultClass="input input-with-password-toggle" />
                                    @else
                                        <x-forms.env-var-input id="value" type="password"
                                            canGate="manageEnvironment" :canResource="$this->resource"
                                            :required="$is_redis_credential" :disabled="!$canEditValue"
                                            :availableVars="$isSharedVariable ? [] : $this->availableSharedVariables"
                                            :hasVaultSource="$this->hasSecretManagerSource()"
                                            :projectUuid="data_get($parameters, 'project_uuid')"
                                            :environmentUuid="data_get($parameters, 'environment_uuid')"
                                            :serverUuid="data_get($parameters, 'server_uuid')" />
                                    @endif
                                </div>
                            </div>
                        @endif
                        {{-- Keep tip mounted during value load so layout does not jump when decrypt finishes. --}}
                        @if (!$isSharedVariable && !$isValueHidden && !$isLocked)
                            <div x-cloak x-show="!isMultiline" wire:key="env-show-value-tip-{{ $env->id }}"
                                class="mt-1.5 text-xs text-neutral-500 dark:text-fg-faint">
                                Tip: Type <span
                                    class="font-mono text-coollabs dark:text-warning">{{ '{{' }}</span> to reference
                                a shared environment variable
                            </div>
                        @endif
                    </div>

                    @if ($is_shared)
                        <x-forms.input disabled type="password" id="real_value" label="Resolved value" />
                    @endif

                    @if ($showValueType || $showInterpolation || $showBuildtime || $showRuntime)
                        <div
                            class="grid gap-4 border-t border-neutral-200 pt-4 dark:border-white/[0.07] sm:grid-cols-2">
                            @if ($showValueType)
                                <x-forms.listbox id="is_multiline" label="Value type" :live="true" :options="[
                                    ['value' => false, 'label' => 'Single line'],
                                    ['value' => true, 'label' => 'Multiline'],
                                ]"
                                    :disabled="! $canUpdate" />
                            @endif
                            @if ($showInterpolation)
                                <x-forms.listbox id="is_literal" label="Interpolation" :options="[
                                    ['value' => false, 'label' => 'Interpolate $VARIABLES'],
                                    ['value' => true, 'label' => 'Literal (keep $ characters as-is)'],
                                ]"
                                    helper="Literal means $VARIABLES in the value is kept as the actual characters '$VARIABLES' instead of being resolved from another variable. Useful when your value contains a $ sign."
                                    :disabled="! $canUpdate" />
                            @endif
                            @if ($showBuildtime)
                                <x-forms.listbox id="is_buildtime" label="Build time" :options="[
                                    ['value' => true, 'label' => 'Available during build'],
                                    ['value' => false, 'label' => 'Not available during build'],
                                ]"
                                    helper="Make this variable available during the Docker build process. Useful for build secrets and dependencies."
                                    :disabled="! $canUpdate" />
                            @endif
                            @if ($showRuntime)
                                <x-forms.listbox id="is_runtime" label="Runtime" :options="[
                                    ['value' => true, 'label' => 'Available in the container'],
                                    ['value' => false, 'label' => 'Not available in the container'],
                                ]" helper="Make this variable available in the running container at runtime."
                                    :disabled="! $canUpdate" />
                            @endif
                        </div>
                    @endif

                    @if (!$isSharedVariable)
                        <x-environment-variable-warning :problematic-variables="$problematicVariables" />
                    @endif

                    @if ($canUpdate || auth()->user()?->can('delete', $this->env))
                        <div
                            class="flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.07]">
                            @if ($canUpdate && !$isLocked && !$isMagicVariable)
                                <x-forms.button type="button" wire:click="lock">Lock</x-forms.button>
                            @endif
                            @can('delete', $this->env)
                                @if (!$isMagicVariable)
                                    <x-modal-confirmation title="Confirm Environment Variable Deletion?" isErrorButton
                                        buttonTitle="Delete" submitAction="delete"
                                        :actions="['The selected environment variable will be permanently deleted.']"
                                        confirmationText="{{ $key }}"
                                        confirmationLabel="Please confirm the execution of the actions by entering the Environment Variable Name below"
                                        shortConfirmationLabel="Environment Variable Name" :confirmWithPassword="false"
                                        step2ButtonText="Permanently Delete" />
                                @endif
                            @endcan
                            @if ($canUpdate)
                                <x-forms.button type="submit" :disabled="$isDisabled" @click="modalOpen = false">
                                    Update variable
                                </x-forms.button>
                            @endif
                        </div>
                    @endif
                </form>
            </x-modal-input>
        </div>
    </div>
</div>
