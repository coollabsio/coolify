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
    <div class="data-table-row env-table-grid">
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
            <span class="truncate font-mono text-[13px] text-black dark:text-fg"
                title="{{ $env->key }}">{{ $env->key }}</span>
            @if ($is_really_required)
                <span class="table-badge table-badge-danger shrink-0">Required</span>
            @endif
            @if ($isMagicVariable)
                <span class="table-badge shrink-0">Managed</span>
            @endif
        </div>
        <div class="text-[13px] text-neutral-500 dark:text-fg-dim">
            {{ $rowScopeLabel }}
        </div>
        <div class="min-w-0 truncate text-[13px] text-neutral-500 dark:text-fg-dim"
            @if ($comment) title="{{ $comment }}" @endif>
            {{ $comment ?: '—' }}
        </div>
        @foreach ([$is_literal, $is_multiline, $is_buildtime && !$isSharedVariable && !$is_redis_credential, $is_runtime && !$isSharedVariable && !$is_redis_credential] as $flag)
            @if ($flag)
                <span class="data-table-cell-check">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                        stroke="currentColor" class="size-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                    </svg>
                </span>
            @else
                <span class="data-table-cell-dash">—</span>
            @endif
        @endforeach
        <div class="justify-self-end">
            <x-modal-input title="Edit environment variable" :closeOutside="false" :wireIgnore="false">
                <x-slot:content>
                    <button type="button"
                        class="cursor-pointer text-[13px] font-medium text-black transition-opacity hover:opacity-70 dark:text-fg">
                        Edit
                    </button>
                </x-slot:content>

                <form wire:submit="submit" class="flex w-full flex-col gap-4"
                    x-data="{ isMultiline: $wire.entangle('is_multiline') }">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-forms.input id="key" label="Name" :required="$is_redis_credential"
                            :disabled="!$canEditValue || $is_redis_credential" />
                        <x-forms.input id="comment" label="Comment" placeholder="Optional note"
                            helper="Add a note to document what this environment variable is used for." maxlength="256"
                            :disabled="!$canUpdate" />
                    </div>

                    <div>
                        @if ($isValueHidden)
                            <label>Value</label>
                            <input disabled type="text" value="Hidden (only admins can view)"
                                class="input w-full italic !text-neutral-500 dark:!text-neutral-500" />
                        @elseif ($isLocked)
                            <label>Value</label>
                            <input disabled type="text" value="Hidden after locking"
                                class="input w-full italic !text-neutral-500 dark:!text-neutral-500" />
                        @else
                            <template x-if="isMultiline">
                                <div wire:key="env-show-value-textarea-{{ $env->id }}">
                                    <x-forms.textarea id="value" label="Value" class="font-sans"
                                        :required="$is_redis_credential" :disabled="!$canEditValue" spellcheck />
                                </div>
                            </template>
                            <template x-if="!isMultiline">
                                <div wire:key="env-show-value-input-{{ $env->id }}">
                                    <x-forms.env-var-input id="value" label="Value" type="password"
                                        :required="$is_redis_credential" :disabled="!$canEditValue"
                                        :availableVars="$isSharedVariable ? [] : $this->availableSharedVariables"
                                        :projectUuid="data_get($parameters, 'project_uuid')"
                                        :environmentUuid="data_get($parameters, 'environment_uuid')"
                                        :serverUuid="data_get($parameters, 'server_uuid')" />
                                </div>
                            </template>
                            @if (!$isSharedVariable)
                                <div x-cloak x-show="!isMultiline" wire:key="env-show-value-tip-{{ $env->id }}"
                                    class="mt-1.5 text-xs text-neutral-500 dark:text-fg-faint">
                                    Tip: Type <span
                                        class="font-mono text-coollabs dark:text-warning">{{ '{{' }}</span> to reference
                                    a shared environment variable
                                </div>
                            @endif
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
                                    x-bind:disabled="@js(!$canUpdate)" />
                            @endif
                            @if ($showInterpolation)
                                <x-forms.listbox id="is_literal" label="Interpolation" :options="[
                                    ['value' => false, 'label' => 'Interpolate $VARIABLES'],
                                    ['value' => true, 'label' => 'Literal (keep $ characters as-is)'],
                                ]"
                                    helper="Literal means $VARIABLES in the value is kept as the actual characters '$VARIABLES' instead of being resolved from another variable. Useful when your value contains a $ sign."
                                    x-bind:disabled="@js(!$canUpdate)" />
                            @endif
                            @if ($showBuildtime)
                                <x-forms.listbox id="is_buildtime" label="Build time" :options="[
                                    ['value' => true, 'label' => 'Available during build'],
                                    ['value' => false, 'label' => 'Not available during build'],
                                ]"
                                    helper="Make this variable available during the Docker build process. Useful for build secrets and dependencies."
                                    x-bind:disabled="@js(!$canUpdate)" />
                            @endif
                            @if ($showRuntime)
                                <x-forms.listbox id="is_runtime" label="Runtime" :options="[
                                    ['value' => true, 'label' => 'Available in the container'],
                                    ['value' => false, 'label' => 'Not available in the container'],
                                ]" helper="Make this variable available in the running container at runtime."
                                    x-bind:disabled="@js(!$canUpdate)" />
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
