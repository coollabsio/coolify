{{-- All SMTP settings: the named servers and the fields of the server in use, in groups.
     The Add server modal also offers the SMTP templates. Used on the transactional email and email
     notification pages (components with ManagesSmtpServers). Put it inside a grid with three columns. --}}
<div class="flex flex-col gap-3 lg:col-span-3">
    @if ($isNewSmtpServer)
        <x-callout type="info" title="New SMTP server: {{ $smtpServerName }}">
            Enter the settings, then save. The server
            {{ $settings->smtpServerName() }} stays in the server list.
            <div class="mt-2">
                <button type="button" class="button" wire:click="cancelNewSmtpServer">Cancel</button>
            </div>
        </x-callout>
    @else
        <div class="flex flex-wrap items-end gap-2">
            <div class="w-full sm:w-72">
                <x-forms.listbox canGate="update" :canResource="$settings" id="selectedSmtpServer" label="SMTP server"
                    onChange="switchSmtpServer" :onChangeArgs="[]"
                    :disabled="!auth()->user()->can('update', $settings)" :options="$this->smtpServerOptions()" />
            </div>
            @can('update', $settings)
                <x-modal-input title="Rename SMTP server">
                    <x-slot:content>
                        {{-- Set the name in the browser: a request would redraw the input and take its focus. --}}
                        <button type="button" class="icon-button mb-0.5" title="Rename server"
                            aria-label="Rename SMTP server {{ $settings->smtpServerName() }}"
                            x-on:click="$wire.renamedSmtpServerName = @js($settings->smtpServerName())">
                            <x-reicon name="edit" class="size-3.5" />
                        </button>
                    </x-slot:content>
                    <form wire:submit.prevent="renameSmtpServer" class="application-settings-form flex flex-col gap-4">
                        <x-forms.input id="renamedSmtpServerName" label="Name" required
                            helper="Name to find this server in the SMTP server list." />
                        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                            <button type="submit" class="button button-highlighted" @click="modalOpen=false">
                                Save
                            </button>
                        </div>
                    </form>
                </x-modal-input>
                @if (count($settings->savedSmtpServers()) > 0)
                    {{-- Keyed by the server name, so that the ignored modal shows the server in use after a switch. --}}
                    <x-modal-confirmation wire:key="delete-smtp-server-{{ md5($settings->smtpServerName()) }}"
                        title="Delete SMTP server?" submitAction="deleteSmtpServer"
                        :actions="['The SMTP server ' . $settings->smtpServerName() . ' is deleted. Coolify then uses the next server in the list.']"
                        :confirmWithText="false" :confirmWithPassword="false"
                        step2ButtonText="Delete server">
                        <x-slot:trigger>
                            <button type="button"
                                class="icon-button mb-0.5 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                title="Delete server" aria-label="Delete SMTP server {{ $settings->smtpServerName() }}">
                                <x-reicon name="trash" class="size-3.5" />
                            </button>
                        </x-slot:trigger>
                    </x-modal-confirmation>
                @endif
                <x-modal-input title="Add SMTP server">
                    <x-slot:content>
                        <button type="button" class="button ml-2">
                            <x-reicon name="plus" class="size-3.5" />
                            Add server
                        </button>
                    </x-slot:content>
                    <form wire:submit.prevent="addSmtpServer" class="application-settings-form flex flex-col gap-4">
                        <x-forms.input id="newSmtpServerName" label="Name" placeholder="PostShiba production"
                            helper="Name to find this server in the SMTP server list." required />
                        <x-forms.listbox id="newSmtpServerTemplate" label="Template" placeholder="None (enter the settings yourself)"
                            helper="Fills in the host, port, and encryption of the provider. You then enter the username and password from the provider."
                            :options="\App\Support\SmtpPresets::options()" />
                        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                            <button type="submit" class="button button-highlighted" @click="modalOpen=false">
                                Add server
                            </button>
                        </div>
                    </form>
                </x-modal-input>
            @endcan
        </div>
    @endif
</div>

@php($smtpGroupClass = 'flex flex-col gap-3 border-t border-neutral-200 pt-4 lg:col-span-3 dark:border-white/[0.08]')
@php($smtpGroupTitleClass = 'text-sm font-semibold text-black dark:text-fg')

<div class="{{ $smtpGroupClass }}">
    <h4 class="{{ $smtpGroupTitleClass }}">Sender</h4>
    <div class="grid gap-4 lg:grid-cols-2">
        <x-forms.input canGate="update" :canResource="$settings" required id="smtpFromName"
            helper="Name shown in email from this server." label="From name" />
        <x-forms.input canGate="update" :canResource="$settings" required id="smtpFromAddress"
            helper="Address used in email from this server. It must be on a domain that this server can send from."
            label="From address" />
    </div>
</div>

<div class="{{ $smtpGroupClass }}">
    <h4 class="{{ $smtpGroupTitleClass }}">Connection</h4>
    <div class="grid gap-4 lg:grid-cols-3">
        <x-forms.input canGate="update" :canResource="$settings" required id="smtpHost"
            placeholder="smtp.mailgun.org" label="Host" />
        <x-forms.input canGate="update" :canResource="$settings" required id="smtpPort" type="number"
            placeholder="587" label="Port" />
        <x-forms.listbox canGate="update" :canResource="$settings" required id="smtpEncryption" label="Encryption"
            :disabled="!auth()->user()->can('update', $settings)" :options="[
                ['value' => 'starttls', 'label' => 'StartTLS'],
                ['value' => 'tls', 'label' => 'TLS / SSL'],
                ['value' => 'none', 'label' => 'None'],
            ]" />
    </div>
</div>

<div class="{{ $smtpGroupClass }}">
    <h4 class="{{ $smtpGroupTitleClass }}">Authentication</h4>
    {{-- Same rule as SmtpPresets::requiresLogin(), so the required marks follow the fields while you type. --}}
    <div class="grid gap-4 lg:grid-cols-2"
        x-data="{ providerHosts: @js(collect(\App\Support\SmtpPresets::PRESETS)->pluck('host')->all()),
            isProviderHost(host) { return this.providerHosts.includes(String(host ?? '').trim().toLowerCase()) } }">
        <x-forms.input canGate="update" :canResource="$settings" id="smtpUsername" label="Username"
            :requiredWhen="'isProviderHost($wire.smtpHost)'"
            helper="The SMTP username from your provider. Leave it empty only for a relay that does not need a login, for example a local mail server." />
        @can('update', $settings)
            <x-forms.input canGate="update" :canResource="$settings" id="smtpPassword" type="password"
                label="Password" autocomplete="new-password"
                :requiredWhen="'isProviderHost($wire.smtpHost) || !! $wire.smtpUsername'"
                helper="The SMTP password from your provider. It is required when you enter a username." />
        @else
            <x-forms.input disabled label="Password" value="Hidden (only admins can view)" />
        @endcan
    </div>
</div>

<div class="{{ $smtpGroupClass }}">
    <h4 class="{{ $smtpGroupTitleClass }}">Advanced</h4>
    <div class="grid gap-4 lg:grid-cols-2">
        <x-forms.input canGate="update" :canResource="$settings" id="smtpTimeout" type="number"
            helper="Maximum delivery time in seconds." label="Timeout" />
        <x-forms.input canGate="update" :canResource="$settings" id="smtpEhloDomain" placeholder="coolify.example.com"
            helper="Fully qualified domain sent in the SMTP EHLO command. Uses the system default when empty."
            label="EHLO domain" />
    </div>
</div>
