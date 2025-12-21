@can('createAnyResource')
    <form wire:submit='createGitHubApp' class="flex flex-col w-full gap-2">
        <div class="pb-2">{{ __('security.required_for_integration') }}</div>
        <div class="flex gap-2">
            <x-forms.input id="name" label="{{ __('security.name_label') }}" required />
            <x-forms.input helper="{{ __('forms.placeholders.github_org_hint') }}"
                placeholder="{{ __('forms.placeholders.github_org_hint') }}" id="organization" label="{{ __('security.organization_on_github') }}" />
        </div>
        @if (!isCloud())
            <div x-data="{ showWarning: @entangle('is_system_wide') }">
                <div class="w-48">
                    <x-forms.checkbox id="is_system_wide" label="{{ __('security.system_wide_label') }}"
                        helper="{{ __('security.system_wide_hint') }}" />
                </div>
                <div x-show="showWarning" x-transition x-cloak class="w-full max-w-2xl mx-auto pt-2">
                    <x-callout type="warning" title="{{ __('security.not_recommended') }}">
                        <div class="whitespace-normal break-words">
                            {{ __('security.system_wide_warning') }}
                        </div>
                    </x-callout>
                </div>
            </div>
        @endif
        <div x-data="{
                                activeAccordion: '',
                                setActiveAccordion(id) {
                                    this.activeAccordion = (this.activeAccordion == id) ? '' : id
                                }
                            }" class="relative w-full py-2 mx-auto overflow-hidden text-sm font-normal rounded-md">
            <div x-data="{ id: $id('accordion') }" class="cursor-pointer">
                <button @click="setActiveAccordion(id)"
                    class="flex items-center justify-between w-full px-1 py-2 text-left select-none dark:hover:text-white hover:bg-white/5"
                    type="button">
                    <h4>{{ __('security.self_hosted_enterprise') }}</h4>
                    <svg class="w-4 h-4 duration-200 ease-out" :class="{ 'rotate-180': activeAccordion == id }"
                        viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor"
                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </button>
                <div x-show="activeAccordion==id" x-collapse x-cloak class="px-2">
                    <div class="flex flex-col gap-2 pt-0 opacity-70">
                        <div class="flex gap-2">
                            <x-forms.input id="html_url" label="{{ __('security.html_url_label') }}" required />
                            <x-forms.input id="api_url" label="{{ __('security.api_url_label') }}" required />
                        </div>
                        <div class="flex gap-2">
                            <x-forms.input id="custom_user" label="{{ __('security.custom_git_user') }}" required />
                            <x-forms.input id="custom_port" type="number" label="{{ __('security.custom_git_port') }}" required />
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <x-forms.button class="mt-4" type="submit">
            {{ __('button.continue') }}
        </x-forms.button>
    </form>
@else
    <x-callout type="warning" title="{{ __('warning.title') }}">
        {{ __('security.insufficient_permissions_hint') }}
    </x-callout>
@endcan