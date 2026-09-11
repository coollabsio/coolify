{{-- Generic renderer for a tool-authored ApprovalForm spec. --}}
{{-- Props: $callId (string), $form (array from ApprovalForm::toArray()). --}}
{{-- Only known field types render; unknown types are ignored (catalog guardrail). --}}
<div class="flex flex-col gap-3">
    @foreach ($form['fields'] as $field)
        @php($model = "approvalInputs.{$callId}.{$field['key']}")
        @php($fieldId = "approval-{$callId}-{$field['key']}")
        @switch($field['type'])
            @case('text')
                <x-forms.input :id="$fieldId" :label="$field['label']" wire:model="{{ $model }}"
                    :required="$field['required']" :helper="$field['help']" />
                @break

            @case('number')
                <x-forms.input type="number" :id="$fieldId" :label="$field['label']" wire:model="{{ $model }}"
                    :required="$field['required']" :helper="$field['help']" />
                @break

            @case('textarea')
                <x-forms.textarea :id="$fieldId" :label="$field['label']" wire:model="{{ $model }}"
                    :required="$field['required']" :helper="$field['help']" />
                @break

            @case('toggle')
                <x-forms.checkbox :id="$fieldId" :label="$field['label']" wire:model="{{ $model }}" />
                @break

            @case('select')
                <x-forms.select :id="$fieldId" :label="$field['label']" wire:model="{{ $model }}">
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
