<form wire:submit='createGitHubApp' class="flex flex-col w-full gap-2">
    <div class="pb-2">This is required, if you would like to get full integration (commit / pull request
        deployments, etc)
        with GitHub.</div>
    <div class="flex gap-2">
        <x-forms.input id="name" label="Name" required />
        <x-forms.input helper="If empty, your GitHub user will be used."
            placeholder="If empty, your GitHub user will be used." id="organization" label="Organization (on GitHub)" />
    </div>
    <div x-data="{
        activeAccordion: '',
        setActiveAccordion(id) {
            this.activeAccordion = (this.activeAccordion == id) ? '' : id
        }
    }" class="relative w-full py-2 mx-auto overflow-hidden text-sm font-normal rounded-md">
        <div x-data="{ id: $id('accordion') }" class="cursor-pointer">
            <button @click="setActiveAccordion(id)"
                class="flex items-center justify-between w-full px-1 py-2 text-left select-none hover:dark:text-white hover:bg-white/5"
                type="button">
                <h4>Advanced</h4>
                <svg class="w-4 h-4 duration-200 ease-out" :class="{ 'rotate-180': activeAccordion == id }"
                    viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor"
                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </button>
            <div x-show="activeAccordion==id" x-collapse x-cloak class="px-2">
                <div class="py-2">Self-hosted / Enterprise GitHub details.</div>
                <div class="flex flex-col gap-2 pt-0 opacity-70">
                    <div class="flex gap-2">
                        <x-forms.input id="html_url" label="HTML Url" required />
                        <x-forms.input id="api_url" label="API Url" required />
                    </div>
                    <div class="flex gap-2">
                        <x-forms.input id="custom_user" label="Custom Git User" required />
                        <x-forms.input id="custom_port" type="number" label="Custom Git Port" required />
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if (!isCloud())
        <x-forms.checkbox id="is_system_wide" label="System Wide" />
    @endif
    <x-forms.button class="mt-4" type="submit">
        Continue
    </x-forms.button>
</form>
