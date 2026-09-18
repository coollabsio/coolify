{{-- Generic renderer for a tool-authored ApprovalForm spec. --}}
{{-- Props: $callId (string), $form (array from ApprovalForm::toArray()). --}}
{{-- Only known field types render; unknown types are ignored (catalog guardrail). --}}
<div class="flex flex-col gap-3">
    @foreach ($form['fields'] as $field)
        {{-- The form components use `id` as their wire:model binding, so passing a
             separate `id` alongside `wire:model` renders a duplicate (and wrong)
             wire:model. Bind with wire:model only. --}}
        @php($model = "approvalInputs.{$callId}.{$field['key']}")
        @switch($field['type'])
            @case('text')
                <x-forms.input :label="$field['label']" wire:model="{{ $model }}"
                    :required="$field['required']" :helper="$field['help']" />
                @break

            @case('number')
                <x-forms.input type="number" :label="$field['label']" wire:model="{{ $model }}"
                    :required="$field['required']" :helper="$field['help']" />
                @break

            @case('textarea')
                <x-forms.textarea :label="$field['label']" wire:model="{{ $model }}"
                    :required="$field['required']" :helper="$field['help']" />
                @break

            @case('toggle')
                <x-forms.checkbox :label="$field['label']" wire:model="{{ $model }}" />
                @break

            @case('select')
                <x-forms.select :label="$field['label']" wire:model="{{ $model }}">
                    @foreach ($field['options'] as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-forms.select>
                @break

            @case('locked')
                <div class="text-sm">
                    <span class="opacity-60">{{ $field['label'] }}:</span>
                    <span class="font-mono">{{ $field['value'] }}</span>
                </div>
                @break

            @case('note')
                <p class="text-sm opacity-80">{{ $field['value'] }}</p>
                @break
        @endswitch
    @endforeach
</div>
