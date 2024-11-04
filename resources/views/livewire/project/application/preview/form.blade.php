<form wire:submit='submit'>
    <div class="flex items-center gap-2">
        <h2>Preview Deployments</h2>
        <x-forms.button type="submit">Save</x-forms.button>
        <x-forms.button isHighlighted wire:click="resetToDefault">Reset template to default</x-forms.button>
    </div>
    <div class="pb-4 ">Preview Deployments based on pull requests are here.</div>
    <div class="flex flex-col gap-2 pb-4">
        <x-forms.input id="previewUrlTemplate" label="Preview URL Template"
            helper="Templates:<span class='text-helper'>@@{{ random }}</span> to generate random sub-domain each time a PR is deployed, <span class='text-helper'>@@{{ pr_id }}</span> to use pull request ID as sub-domain or <span class='text-helper'>@@{{ domain }}</span> to replace the domain name with the application's domain name." />
        @if ($previewUrlTemplate)
            <div class="">Domain Preview: {{ $previewUrlTemplate }}</div>
        @endif
    </div>
</form>
