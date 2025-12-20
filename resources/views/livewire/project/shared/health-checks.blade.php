<form wire:submit='submit' class="flex flex-col">
    <div class="flex items-center gap-2">
        <h2>{{ __('health_check.title') }}</h2>
        <x-forms.button canGate="update" :canResource="$resource" type="submit">{{ __('button.save') }}</x-forms.button>
        @if (!$healthCheckEnabled)
            <x-modal-confirmation title="{{ __('health_check.confirm_enable_title') }}" buttonTitle="{{ __('health_check.enable_button') }}"
                submitAction="toggleHealthcheck" :actions="[__('health_check.enable_action')]"
                warningMessage="{{ __('health_check.enable_warning') }}"
                step2ButtonText="{{ __('health_check.enable_button') }}" :confirmWithText="false" :confirmWithPassword="false"
                isHighlightedButton>
            </x-modal-confirmation>
        @else
            <x-forms.button canGate="update" :canResource="$resource" wire:click="toggleHealthcheck">{{ __('health_check.disable_button') }}</x-forms.button>
        @endif
    </div>
    <div class="mt-1 pb-4">{{ __('health_check.description') }}</div>
    <div class="flex flex-col gap-4">
        @if ($customHealthcheckFound)
            <x-callout type="warning" title="{{ __('health_check.caution') }}">
                <p>{{ __('health_check.custom_detected') }}</p>
            </x-callout>
        @endif
        <div class="flex gap-2">
            <x-forms.select canGate="update" :canResource="$resource" id="healthCheckMethod" label="{{ __('health_check.method') }}" required>
                <option value="GET">GET</option>
                <option value="POST">POST</option>
            </x-forms.select>
            <x-forms.select canGate="update" :canResource="$resource" id="healthCheckScheme" label="{{ __('health_check.scheme') }}" required>
                <option value="http">http</option>
                <option value="https">https</option>
            </x-forms.select>
            <x-forms.input canGate="update" :canResource="$resource" id="healthCheckHost" placeholder="localhost" label="{{ __('health_check.host') }}" required />
            <x-forms.input canGate="update" :canResource="$resource" type="number" id="healthCheckPort"
                helper="{{ __('health_check.port_helper') }}" placeholder="80" label="{{ __('health_check.port') }}" />
            <x-forms.input canGate="update" :canResource="$resource" id="healthCheckPath" placeholder="/health" label="{{ __('health_check.path') }}" required />
        </div>
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$resource" type="number" id="healthCheckReturnCode" placeholder="200" label="{{ __('health_check.return_code') }}"
                required />
            <x-forms.input canGate="update" :canResource="$resource" id="healthCheckResponseText" placeholder="OK" label="{{ __('health_check.response_text') }}" />
        </div>
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$resource" min="1" type="number" id="healthCheckInterval" placeholder="30"
                label="{{ __('health_check.interval') }}" required />
            <x-forms.input canGate="update" :canResource="$resource" type="number" id="healthCheckTimeout" placeholder="30" label="{{ __('health_check.timeout') }}"
                required />
            <x-forms.input canGate="update" :canResource="$resource" type="number" id="healthCheckRetries" placeholder="3" label="{{ __('health_check.retries') }}" required />
            <x-forms.input canGate="update" :canResource="$resource" min=1 type="number" id="healthCheckStartPeriod" placeholder="30"
                label="{{ __('health_check.start_period') }}" required />
        </div>
    </div>
</form>