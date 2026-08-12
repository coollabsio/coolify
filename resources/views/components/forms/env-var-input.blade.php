<div class="w-full">
    @if ($label)
        <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
            <label class="mb-0! flex items-center gap-1 text-sm font-medium leading-4">{{ $label }}
                @if ($required)
                    <x-highlighted text="*" />
                @endif
            </label>
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </div>
    @endif

    <div class="relative" @success.window="type = '{{ $type }}'" x-data="{
            type: '{{ $type }}',
            showDropdown: false,
            suggestions: [],
            selectedIndex: 0,
            cursorPosition: 0,
            currentScope: null,
            availableVars: @js($availableVars),
            get availableScopes() {
                // Only include scopes that have at least one variable
                const allScopes = ['team', 'project', 'environment', 'server'];
                return allScopes.filter(scope => {
                    const vars = this.availableVars[scope];
                    return vars && vars.length > 0;
                });
            },
            scopeUrls: @js($scopeUrls),

            handleInput() {
                const input = this.$refs.input;
                if (!input) return;

                const value = input.value || '';

                this.cursorPosition = input.selectionStart || 0;
                const textBeforeCursor = value.substring(0, this.cursorPosition);

                const openBraces = '{' + '{';
                const lastBraceIndex = textBeforeCursor.lastIndexOf(openBraces);

                if (lastBraceIndex === -1) {
                    this.showDropdown = false;
                    return;
                }

                if (lastBraceIndex > 0 && textBeforeCursor[lastBraceIndex - 1] === '{') {
                    this.showDropdown = false;
                    return;
                }

                const textAfterBrace = textBeforeCursor.substring(lastBraceIndex);
                const closeBraces = '}' + '}';
                if (textAfterBrace.includes(closeBraces)) {
                    this.showDropdown = false;
                    return;
                }

                const content = textAfterBrace.substring(2).trim();

                if (content === '') {
                    this.currentScope = null;
                    // Only show dropdown if there are available scopes with variables
                    if (this.availableScopes.length === 0) {
                        this.showDropdown = false;
                        return;
                    }
                    this.suggestions = this.availableScopes.map(scope => ({
                        type: 'scope',
                        value: scope,
                        display: scope
                    }));
                    this.selectedIndex = 0;
                    this.showDropdown = true;
                } else if (content.includes('.')) {
                    const [scope, partial] = content.split('.');

                    if (!this.availableScopes.includes(scope)) {
                        this.showDropdown = false;
                        return;
                    }

                    this.currentScope = scope;
                    const scopeVars = this.availableVars[scope] || [];
                    const filtered = scopeVars.filter(v =>
                        v.toLowerCase().includes((partial || '').toLowerCase())
                    );

                    if (filtered.length === 0 && scopeVars.length === 0) {
                        this.suggestions = [];
                        this.showDropdown = true;
                    } else {
                        this.suggestions = filtered.map(varName => ({
                            type: 'variable',
                            value: varName,
                            display: `${scope}.${varName}`,
                            scope: scope
                        }));
                        this.selectedIndex = 0;
                        this.showDropdown = filtered.length > 0;
                    }
                } else {
                    this.currentScope = null;
                    const filtered = this.availableScopes.filter(scope =>
                        scope.toLowerCase().includes(content.toLowerCase())
                    );

                    this.suggestions = filtered.map(scope => ({
                        type: 'scope',
                        value: scope,
                        display: scope
                    }));
                    this.selectedIndex = 0;
                    this.showDropdown = filtered.length > 0;
                }
            },

            getScopeUrl(scope) {
                return this.scopeUrls[scope] || this.scopeUrls['default'];
            },

            selectSuggestion(suggestion) {
                const input = this.$refs.input;
                if (!input) return;

                const value = input.value || '';
                const textBeforeCursor = value.substring(0, this.cursorPosition);
                const textAfterCursor = value.substring(this.cursorPosition);
                const openBraces = '{' + '{';
                const lastBraceIndex = textBeforeCursor.lastIndexOf(openBraces);

                if (suggestion.type === 'scope') {
                    const newText = value.substring(0, lastBraceIndex) +
                                  openBraces + ' ' + suggestion.value + '.' +
                                  textAfterCursor;
                    input.value = newText;
                    this.cursorPosition = lastBraceIndex + 3 + suggestion.value.length + 1;

                    this.$nextTick(() => {
                        input.setSelectionRange(this.cursorPosition, this.cursorPosition);
                        input.focus();
                        this.handleInput();
                    });
                } else {
                    const closeBraces = '}' + '}';
                    const newText = value.substring(0, lastBraceIndex) +
                                  openBraces + ' ' + suggestion.display + ' ' + closeBraces +
                                  textAfterCursor;
                    input.value = newText;
                    this.cursorPosition = lastBraceIndex + 3 + suggestion.display.length + 3;

                    input.dispatchEvent(new Event('input'));

                    this.showDropdown = false;
                    this.selectedIndex = 0;

                    this.$nextTick(() => {
                        input.setSelectionRange(this.cursorPosition, this.cursorPosition);
                        input.focus();
                    });
                }
            },

            handleKeydown(event) {
                if (!this.showDropdown) return;
                if (!this.suggestions || this.suggestions.length === 0) return;

                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    this.selectedIndex = (this.selectedIndex + 1) % this.suggestions.length;
                    this.$nextTick(() => {
                        const el = document.getElementById('suggestion-' + this.selectedIndex);
                        if (el) {
                            el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }
                    });
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    this.selectedIndex = this.selectedIndex <= 0 ? this.suggestions.length - 1 : this.selectedIndex - 1;
                    this.$nextTick(() => {
                        const el = document.getElementById('suggestion-' + this.selectedIndex);
                        if (el) {
                            el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }
                    });
                } else if (event.key === 'Enter' && this.showDropdown) {
                    event.preventDefault();
                    if (this.suggestions[this.selectedIndex]) {
                        this.selectSuggestion(this.suggestions[this.selectedIndex]);
                    }
                } else if (event.key === 'Escape') {
                    this.showDropdown = false;
                }
            }
        }"
        @click.outside="showDropdown = false">

        <input
            x-ref="input"
            @input="handleInput()"
            @keydown="handleKeydown($event)"
            @click="handleInput()"
            autocomplete="{{ $autocomplete }}"
            x-bind:type="type"
            x-bind:class="{ 'truncate': type === 'text' && ! $el.disabled }"
            {{ $attributes->merge(['class' => $defaultClass]) }}
            @required($required)
            @readonly($readonly)
            @if ($modelBinding !== 'null')
                wire:model="{{ $modelBinding }}"
                wire:dirty.class="[box-shadow:inset_4px_0_0_#6b16ed,inset_0_0_0_2px_#e5e5e5] dark:[box-shadow:inset_4px_0_0_#fcd452,inset_0_0_0_2px_#242424]"
            @endif
            wire:loading.attr="disabled"
            @disabled($disabled)
            @if ($type !== 'password')
                type="{{ $type }}"
            @endif
            @if ($htmlId !== 'null') id="{{ $htmlId }}" @endif
            name="{{ $name }}"
            placeholder="{{ $attributes->get('placeholder') }}"
            @if ($autofocus) autofocus @endif>

        @if ($type === 'password' && $allowToPeak)
            <button type="button" x-on:click="type = type === 'password' ? 'text' : 'password'"
                class="password-toggle flex absolute inset-y-0 right-0 z-10 items-center pr-2 cursor-pointer text-neutral-500 hover:text-black dark:text-neutral-400 dark:hover:text-white"
                aria-label="Toggle password visibility">
                <x-reicon name="eye" x-show="type === 'password'" class="size-[18px]" />
                <x-reicon name="eye-off2" x-cloak x-show="type === 'text'" class="size-[18px]" />
            </button>
        @endif

        {{-- Dropdown for suggestions --}}
        <div x-show="showDropdown" x-cloak x-transition.origin.top
            class="listbox-panel top-full! z-[60]! mt-1! w-full! min-w-0! max-w-full!" role="listbox">

            <template x-if="suggestions.length === 0 && currentScope">
                <div class="px-2 py-2 text-sm text-neutral-500 dark:text-fg-dim">
                    <div>No shared variables found in <span class="font-semibold" x-text="currentScope"></span> scope.</div>
                    <a :href="getScopeUrl(currentScope)"
                       class="mt-1 inline-block text-xs text-coollabs hover:underline dark:text-warning"
                       target="_blank">
                        Add <span x-text="currentScope"></span> variables →
                    </a>
                </div>
            </template>

            <div x-show="suggestions.length > 0"
                 x-ref="dropdownList"
                 class="max-h-48 overflow-y-auto"
                 style="scrollbar-width: thin;">
                <template x-for="(suggestion, index) in suggestions" :key="index">
                    <div :id="'suggestion-' + index"
                         @click="selectSuggestion(suggestion)"
                         class="listbox-option justify-start! gap-2.5!"
                         :class="{ 'bg-neutral-100 dark:bg-white/[0.08]': index === selectedIndex }"
                         role="option" :aria-selected="index === selectedIndex">
                        <template x-if="suggestion.type === 'scope'">
                            <span class="rounded-md border border-warning/25 bg-warning/10 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-warning">
                                SCOPE
                            </span>
                        </template>
                        <template x-if="suggestion.type === 'variable'">
                            <span class="rounded-md border border-emerald-500/25 bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-emerald-600 dark:text-emerald-400">
                                VAR
                            </span>
                        </template>
                        <span class="min-w-0 truncate font-mono text-sm" x-text="suggestion.display"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>

    @if (!$label && $helper)
        <x-helper :helper="$helper" />
    @endif
    @error($modelBinding)
        <label class="label">
            <span class="text-red-500 label-text-alt">{{ $message }}</span>
        </label>
    @enderror
</div>
