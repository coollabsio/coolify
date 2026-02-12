<form wire:submit='submit'>
    <div class="form-card">
        <div class="form-section-title">
            <h2>Preview Deployments</h2>
            <div class="flex items-center gap-2">
                @can('update', $application)
                    <x-forms.button type="submit">Save</x-forms.button>
                    <x-forms.button isHighlighted wire:click="resetToDefault">Reset template to default</x-forms.button>
                @endcan
            </div>
        </div>
        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">Preview Deployments based on pull requests are here.</p>
        <div class="flex flex-col gap-10">
        <x-forms.input id="previewUrlTemplate" label="Preview URL Template"
            helper="Templates:<br/><span class='text-helper'>@@{{ random }}</span> to generate random sub-domain each time a PR is deployed<br/><span class='text-helper'>@@{{ pr_id }}</span> to use pull request ID as sub-domain or <span class='text-helper'>@@{{ domain }}</span> to replace the domain name with the application's domain name." canGate="update" :canResource="$application" />
        @if ($previewUrlTemplate)
            <div class="">Domain Preview: {{ $previewUrlTemplate }}</div>
        @endif
        </div>
    </div>
</form>
