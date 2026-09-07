@props([
    'label' => 'Domains',
    'helper' => 'Add one domain per entry. Press Enter (or type a comma) to add it. You can specify a path and a port to bind the domain to.<br><br><span class=\'text-helper\'>Example</span><br>- https://app.coolify.io/api/v3<br>- https://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container.<br>- https://app.coolify.io:8080/api -> app.coolify.io/api will point to port 8080 inside the container.',
    'model' => 'fqdn',
    'disabled' => false,
    'required' => false,
    'placeholder' => 'https://coolify.io',
    'canUpdate' => null,
])

@php
    $canEdit = $canUpdate ?? ! $disabled;
@endphp

<div {{ $attributes->class(['w-full']) }}>
    @if ($label)
        <label class="mb-1 flex w-fit items-center gap-1.5 text-sm font-medium">
            {{ $label }}
            @if ($required)
                <x-highlighted text="*" />
            @endif
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </label>
    @endif
    <div class="chip-input" x-data="{
        raw: @entangle($model),
        entry: '',
        canUpdate: @js((bool) $canEdit),
        get domains() {
            return (this.raw ?? '').split(',').map((domain) => domain.trim()).filter(Boolean);
        },
        commit(list) {
            this.raw = list.join(',');
        },
        addValue(value) {
            value = value.trim().replace(/,+$/, '');
            if (!value) return;
            const list = this.domains;
            if (!list.includes(value)) {
                list.push(value);
                this.commit(list);
            }
        },
        add() {
            const value = this.entry;
            this.entry = '';
            this.addValue(value);
        },
        onInput() {
            if (this.entry.includes(',')) {
                const parts = this.entry.split(',');
                this.entry = parts.pop().trim();
                parts.forEach((part) => this.addValue(part));
            }
        },
        remove(index) {
            if (!this.canUpdate) return;
            const list = this.domains;
            list.splice(index, 1);
            this.commit(list);
        },
        onKeydown(event) {
            if (event.key === ',') {
                event.preventDefault();
                this.add();
            } else if (event.key === 'Backspace' && this.entry === '') {
                this.remove(this.domains.length - 1);
            }
        }
    }" @click="$refs.domainEntry.focus()">
        <template x-for="(domain, index) in domains" :key="domain + '-' + index">
            <span class="chip">
                <span x-text="domain"></span>
                <button type="button" class="chip-remove" x-show="canUpdate"
                    :aria-label="'Remove ' + domain" @click.stop="remove(index)">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                        stroke-width="2" stroke="currentColor" class="size-3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </span>
        </template>
        <input x-ref="domainEntry" x-model="entry"
            :placeholder="domains.length === 0 ? @js($placeholder) : ''"
            autocomplete="off" spellcheck="false" @input="onInput()"
            @keydown.enter.prevent.stop="add()" x-on:keydown="onKeydown($event)"
            @blur="add()" @disabled($disabled) x-bind:disabled="!canUpdate" />
    </div>
</div>
