<form wire:submit='submit' class="flex flex-col">
    <div class="flex items-center gap-2">
        <h2>Healthcheck</h2>
        <x-forms.button canGate="update" :canResource="$database" type="submit">Save</x-forms.button>
        @if (!$healthCheckEnabled)
            <x-modal-confirmation title="Confirm Healthcheck Enable?" buttonTitle="Enable Healthcheck"
                submitAction="toggleHealthcheck" :actions="['Enable healthcheck for this database.']"
                warningMessage="If the health check fails, this database will be marked unhealthy. Please review the <a href='https://coolify.io/docs/knowledge-base/health-checks' target='_blank' class='underline text-white'>Health Checks</a> guide before proceeding!"
                step2ButtonText="Enable Healthcheck" :confirmWithText="false" :confirmWithPassword="false"
                isHighlightedButton>
            </x-modal-confirmation>
        @else
            <x-forms.button canGate="update" :canResource="$database" wire:click="toggleHealthcheck">Disable Healthcheck</x-forms.button>
        @endif
    </div>
    <div class="mt-1 pb-4">Define how your resource's health should be checked.</div>
    <div class="flex flex-col gap-4">
        @if (!$healthCheckEnabled)
            <x-callout type="warning" title="Healthcheck disabled">
                <p>Docker runs no healthcheck probe for this database and Coolify can no longer report a healthy/unhealthy state.</p>
            </x-callout>
        @endif

        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$database" min="1" type="number" id="healthCheckInterval"
                placeholder="15" label="Interval (s)" required />
            <x-forms.input canGate="update" :canResource="$database" min="1" type="number" id="healthCheckTimeout"
                placeholder="5" label="Timeout (s)" required />
            <x-forms.input canGate="update" :canResource="$database" min="1" type="number" id="healthCheckRetries"
                placeholder="5" label="Retries" required />
            <x-forms.input canGate="update" :canResource="$database" min="0" type="number"
                id="healthCheckStartPeriod" placeholder="5" label="Start Period (s)" required />
        </div>
    </div>
</form>
