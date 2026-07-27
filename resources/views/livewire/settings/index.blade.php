<div>
    <x-slot:title>
        Settings | Coolify
    </x-slot>

    <x-settings.navbar />

    <div
        class="application-settings-workspace mx-auto grid w-full max-w-[1180px] min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-settings.sidebar activeMenu="general" />

        <form wire:submit="submit" class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="General">
                <div class="grid gap-4 lg:grid-cols-2">
                    <div class="lg:col-span-2">
                        <x-forms.input canGate="update" :canResource="$settings" id="fqdn" label="URL"
                            helper="Enter the full URL of the instance (for example, https://dashboard.example.com).<br><br><span class='text-coollabs dark:text-warning'>Important:</span> Include <b>https://</b> to secure the dashboard with HTTPS."
                            placeholder="https://coolify.yourdomain.com" />
                    </div>

                    <x-forms.input canGate="update" :canResource="$settings" id="instance_name" label="Name"
                        placeholder="Coolify" helper="Custom name for this Coolify instance." />

                    <div x-data="{
                        open: false,
                        search: @js($settings->instance_timezone ?: ''),
                        timezones: @js($this->timezones),
                        get filteredTimezones() {
                            const query = this.search.toLowerCase();
                            return this.timezones.filter(timezone => timezone.toLowerCase().includes(query)).slice(0, 100);
                        },
                        selectTimezone(timezone) {
                            this.search = timezone;
                            this.open = false;
                            this.$wire.set('instance_timezone', timezone);
                            this.$wire.submit();
                        }
                    }" @click.outside="open = false" class="w-full">
                        <label for="instance_timezone" class="mb-1.5 flex w-fit items-center gap-1.5">
                            Instance timezone
                            <x-helper
                                helper="Timezone used for update checks and the automatic update schedule." />
                        </label>
                        <div class="relative">
                            <input id="instance_timezone" autocomplete="off" x-model="search"
                                @focus="open = true" @input="open = true"
                                class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-8! pl-3! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-neutral-300! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint"
                                placeholder="Search timezones" @disabled(!auth()->user()->can('update', $settings))>
                            <x-reicon name="search"
                                class="pointer-events-none absolute top-1/2 right-2.5 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />

                            <div x-cloak x-show="open" x-transition.origin.top
                                class="absolute top-9 right-0 left-0 z-50 max-h-60 overflow-y-auto rounded-lg border border-neutral-200 bg-white p-1 shadow-modal dark:border-white/[0.1] dark:bg-raised">
                                <template x-for="timezone in filteredTimezones" :key="timezone">
                                    <button type="button"
                                        class="flex h-8 w-full items-center rounded-md px-2 text-left text-[12px] text-neutral-600 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                        @click="selectTimezone(timezone)">
                                        <span class="truncate" x-text="timezone"></span>
                                    </button>
                                </template>
                                <p x-show="filteredTimezones.length === 0"
                                    class="px-2 py-3 text-center text-[12px] text-neutral-500 dark:text-fg-dim">
                                    No matching timezone
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </x-application.settings-section>

            <x-application.settings-section title="Network addresses">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input canGate="update" :canResource="$settings" id="public_ipv4" type="password"
                        label="Instance public IPv4"
                        helper="Set this when Coolify cannot detect the correct public IPv4 address."
                        placeholder="1.2.3.4" autocomplete="new-password" />
                    <x-forms.input canGate="update" :canResource="$settings" id="public_ipv6" type="password"
                        label="Instance public IPv6"
                        helper="Set this when Coolify cannot detect the correct public IPv6 address."
                        placeholder="2001:db8::1" autocomplete="new-password" />
                </div>
            </x-application.settings-section>

            @if (isDev())
                <x-application.settings-section title="Development helper">
                    <x-forms.input canGate="update" :canResource="$settings" id="dev_helper_version"
                        label="Version override"
                        helper="Override the default coolify-helper image version. Leave empty to use {{ config('constants.coolify.helper_version') }}."
                        placeholder="{{ config('constants.coolify.helper_version') }}" />
                </x-application.settings-section>
            @endif
        </form>
    </div>

    <x-domain-conflict-modal :conflicts="$domainConflicts" :showModal="$showDomainConflictModal"
        confirmAction="confirmDomainUsage">
        <x-slot:consequences>
            <ul class="mt-2 ml-4 list-disc">
                <li>The Coolify instance domain will conflict with existing resources.</li>
                <li>SSL certificates might not work correctly.</li>
                <li>Routing behavior will be unpredictable.</li>
                <li>You may not be able to access the Coolify dashboard properly.</li>
            </ul>
        </x-slot:consequences>
    </x-domain-conflict-modal>
</div>
