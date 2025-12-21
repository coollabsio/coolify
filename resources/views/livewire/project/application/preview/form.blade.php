<form wire:submit='submit'>
    <div class="flex items-center gap-2">
        <h2>{{ __('application.preview_deployments') }}</h2>
        @can('update', $application)
            <x-forms.button type="submit">{{ __('common.save') }}</x-forms.button>
            <x-forms.button isHighlighted wire:click="resetToDefault">{{ __('common.reset_to_default') }}</x-forms.button>
        @endcan
    </div>
    <div class="pb-4 ">{{ __('application.preview_deployments_based_on_pr') }}</div>
    <div class="flex flex-col gap-2 pb-4">
        <x-forms.input id="previewUrlTemplate" label="{{ __('application.preview_url_template') }}"
            helper="{{ __('application.preview_url_template_helper') }}" canGate="update" :canResource="$application" />
        @if ($previewUrlTemplate)
            <div class="">{{ __('application.domain_preview') }} {{ $previewUrlTemplate }}</div>
        @endif
    </div>
</form>
