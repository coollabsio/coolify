<form wire:submit='submit' class="flex flex-col">
    <div class="flex items-center gap-2">
        <h2>Healthchecks</h2>
        <x-forms.button canGate="update" :canResource="$resource" type="submit">Save</x-forms.button>
        @if (!$healthCheckEnabled)
            <x-modal-confirmation title="Confirm Healthcheck Enable?" buttonTitle="Enable Healthcheck"
                submitAction="toggleHealthcheck" :actions="['Enable healthcheck for this resource.']"
                warningMessage="If the health check fails, your application will become inaccessible. Please review the <a href='https://coolify.io/docs/knowledge-base/health-checks' target='_blank' class='underline text-white'>Health Checks</a> guide before proceeding!"
                step2ButtonText="Enable Healthcheck" :confirmWithText="false" :confirmWithPassword="false"
                isHighlightedButton>
            </x-modal-confirmation>
        @else
            <x-forms.button canGate="update" :canResource="$resource" wire:click="toggleHealthcheck">Disable Healthcheck</x-forms.button>
        @endif
    </div>
    <div class="mt-1 pb-4">Define how your resource's health should be checked.</div>
    <div class="flex flex-col gap-4">
        @if ($customHealthcheckFound)
            <x-callout type="warning" title="Caution">
                <p>A custom health check has been detected. If you enable this health check, it will disable the custom one and use this instead.</p>
            </x-callout>
        @endif
        <div class="flex gap-2">
            <x-forms.select canGate="update" :canResource="$resource" id="healthCheckMethod" label="Method" required>
                <option value="GET">GET</option>
                <option value="POST">POST</option>
            </x-forms.select>
            <x-forms.select canGate="update" :canResource="$resource" id="healthCheckScheme" label="Scheme" required>
                <option value="http">http</option>
                <option value="https">https</option>
            </x-forms.select>
            <x-forms.input canGate="update" :canResource="$resource" id="healthCheckHost" placeholder="localhost" label="Host" required />
            <x-forms.input canGate="update" :canResource="$resource" type="number" id="healthCheckPort"
                helper="If no port is defined, the first exposed port will be used." placeholder="80" label="Port" />
            <x-forms.input canGate="update" :canResource="$resource" id="healthCheckPath" placeholder="/health" label="Path" required />
        </div>
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$resource" type="number" id="healthCheckReturnCode" placeholder="200" label="Return Code"
                required />
            <x-forms.input canGate="update" :canResource="$resource" id="healthCheckResponseText" placeholder="OK" label="Response Text" />
        </div>
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$resource" min="1" type="number" id="healthCheckInterval" placeholder="30"
                label="Interval (s)" required />
            <x-forms.input canGate="update" :canResource="$resource" type="number" id="healthCheckTimeout" placeholder="30" label="Timeout (s)"
                required />
            <x-forms.input canGate="update" :canResource="$resource" type="number" id="healthCheckRetries" placeholder="3" label="Retries" required />
            <x-forms.input canGate="update" :canResource="$resource" min=1 type="number" id="healthCheckStartPeriod" placeholder="30"
                label="Start Period (s)" required />
        </div>
    </div>
</form>