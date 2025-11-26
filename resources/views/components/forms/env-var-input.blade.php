<div class="w-full">
    @if ($label)
        <label class="flex gap-1 items-center mb-1 text-sm font-medium">{{ $label }}
            @if ($required)
                <x-highlighted text="*" />
            @endif
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </label>
    @endif

    <div x-data="{
            showDropdown: false,
            suggestions: [],
            selectedIndex: 0,
            cursorPosition: 0,
            currentScope: null,
            availableScopes: ['team', 'project', 'environment'],
            availableVars: @js($availableVars),
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
        @click.outside="showDropdown = false"
        class="relative">

        <input
            x-ref="input"
            @input="handleInput()"
            @keydown="handleKeydown($event)"
            @click="handleInput()"
            autocomplete="{{ $autocomplete }}"
            {{ $attributes->merge(['class' => $defaultClass]) }}
            @required($required)
            @readonly($readonly)
            @if ($modelBinding !== 'null')
                wire:model="{{ $modelBinding }}"
                wire:dirty.class="dark:border-l-warning border-l-coollabs border-l-4"
            @endif
            wire:loading.attr="disabled"
            type="{{ $type }}"
            @disabled($disabled)
            @if ($htmlId !== 'null') id="{{ $htmlId }}" @endif
            name="{{ $name }}"
            placeholder="{{ $attributes->get('placeholder') }}"
            @if ($autofocus) autofocus @endif>

        {{-- Dropdown for suggestions --}}
        <div x-show="showDropdown"
             x-transition
             class="absolute z-[60] w-full mt-1 bg-white dark:bg-coolgray-100 border border-neutral-300 dark:border-coolgray-400 rounded shadow-lg">

            <template x-if="suggestions.length === 0 && currentScope">
                <div class="px-3 py-2 text-sm text-neutral-500 dark:text-neutral-400">
                    <div>No shared variables found in <span class="font-semibold" x-text="currentScope"></span> scope.</div>
                    <a :href="getScopeUrl(currentScope)"
                       class="text-coollabs dark:text-warning hover:underline text-xs mt-1 inline-block"
                       target="_blank">
                        Add <span x-text="currentScope"></span> variables →
                    </a>
                </div>
            </template>

            <div x-show="suggestions.length > 0"
                 x-ref="dropdownList"
                 class="max-h-48 overflow-y-scroll"
                 style="scrollbar-width: thin;">
                <template x-for="(suggestion, index) in suggestions" :key="index">
                    <div :id="'suggestion-' + index"
                         @click="selectSuggestion(suggestion)"
                         class="px-3 py-2 cursor-pointer hover:bg-neutral-100 dark:hover:bg-coolgray-200 flex items-center gap-2"
                         :class="{ 'bg-neutral-50 dark:bg-coolgray-300': index === selectedIndex }">
                        <template x-if="suggestion.type === 'scope'">
                            <span class="text-xs px-2 py-0.5 bg-coollabs/10 dark:bg-warning/10 text-coollabs dark:text-warning rounded">
                                SCOPE
                            </span>
                        </template>
                        <template x-if="suggestion.type === 'variable'">
                            <span class="text-xs px-2 py-0.5 bg-green-500/10 text-green-600 dark:text-green-400 rounded">
                                VAR
                            </span>
                        </template>
                        <span class="text-sm font-mono" x-text="suggestion.display"></span>
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
