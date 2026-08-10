<form wire:submit="submit" class="application-settings-form flex flex-col gap-6">
    <x-unsaved-bar action="submit" />

    <x-application.settings-section title="Healthcheck"
        description="Define how Docker checks this database and reports its health.">
        <x-slot:actions>
            @if (!$healthCheckEnabled)
                <x-modal-confirmation title="Enable healthcheck?" buttonTitle="Enable healthcheck"
                    submitAction="toggleHealthcheck" :actions="['Enable healthcheck for this database.']"
                    warningMessage="A failing probe marks the database unhealthy. Review the health check guide before enabling it."
                    step2ButtonText="Enable healthcheck" :confirmWithText="false" :confirmWithPassword="false"
                    isHighlightedButton />
            @else
                <x-forms.button canGate="update" :canResource="$database"
                    wire:click="toggleHealthcheck" type="button">Disable healthcheck</x-forms.button>
            @endif
        </x-slot:actions>

        @if (!$healthCheckEnabled)
            <x-callout type="warning" title="Healthcheck disabled">
                Docker is not running a healthcheck probe, so Coolify cannot report a healthy or unhealthy state.
            </x-callout>
        @endif

        <div class="{{ !$healthCheckEnabled ? 'mt-4 ' : '' }}grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-forms.input canGate="update" :canResource="$database" min="1" type="number"
                id="healthCheckInterval" placeholder="15" label="Interval (seconds)" required />
            <x-forms.input canGate="update" :canResource="$database" min="1" type="number"
                id="healthCheckTimeout" placeholder="5" label="Timeout (seconds)" required />
            <x-forms.input canGate="update" :canResource="$database" min="1" type="number"
                id="healthCheckRetries" placeholder="5" label="Retries" required />
            <x-forms.input canGate="update" :canResource="$database" min="0" type="number"
                id="healthCheckStartPeriod" placeholder="5" label="Start period (seconds)" required />
        </div>
    </x-application.settings-section>
</form>
