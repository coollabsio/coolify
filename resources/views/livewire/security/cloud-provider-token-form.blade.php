<div class="w-full">
    <form class="flex flex-col gap-2 {{ $modal_mode ? 'w-full' : '' }}" wire:submit='addToken'>
        @if ($modal_mode)
            {{-- Modal layout: vertical, compact --}}
            @if (!isset($provider) || empty($provider) || $provider === '')
                <x-forms.select required id="provider" label="Provider">
                    <option value="hetzner">Hetzner</option>
                    <option value="digitalocean">DigitalOcean</option>
                </x-forms.select>
            @else
                <input type="hidden" wire:model="provider" />
            @endif

            <x-forms.input required id="name" label="Token Name"
                placeholder="e.g., Production Hetzner. tip: add Hetzner project name to identify easier" />

            <x-forms.input required type="password" id="token" label="API Token"
                placeholder="Enter your API token" />

            @if (auth()->user()->currentTeam()->cloudProviderTokens->where('provider', $provider)->isEmpty())
                <div class="text-sm text-neutral-500 dark:text-neutral-400">
                    Create an API token in the <a
                        href='{{ $provider === 'hetzner' ? 'https://console.hetzner.com/projects' : '#' }}'
                        target='_blank' class='underline dark:text-white'>{{ ucfirst($provider) }} Console</a> → choose
                    Project → Security → API Tokens.
                    @if ($provider === 'hetzner')
                        <br><br>
                        Don't have a Hetzner account? <a href='https://coolify.io/hetzner' target='_blank'
                            class='underline dark:text-white'>Sign up here</a>
                        <br>
                        <span class="text-xs">(Coolify's affiliate link, only new accounts - supports us (€10)
                            and gives you €20)</span>
                    @endif
                </div>
            @endif

            <x-forms.button type="submit">Validate & Add Token</x-forms.button>
        @else
            {{-- Full page layout: horizontal, spacious --}}
            <div class="flex gap-2 items-end flex-wrap">
                <div class="w-64">
                    <x-forms.select required id="provider" label="Provider" disabled>
                        <option value="hetzner" selected>Hetzner</option>
                        <option value="digitalocean">DigitalOcean</option>
                    </x-forms.select>
                </div>
                <div class="flex-1 min-w-64">
                    <x-forms.input required id="name" label="Token Name"
                        placeholder="e.g., Production Hetzner. tip: add Hetzner project name to identify easier" />
                </div>
            </div>
            <div class="flex-1 min-w-64">
                <x-forms.input required type="password" id="token" label="API Token"
                    placeholder="Enter your API token" />
                @if (auth()->user()->currentTeam()->cloudProviderTokens->where('provider', $provider)->isEmpty())
                    <div class="text-sm text-neutral-500 dark:text-neutral-400 mt-2">
                        Create an API token in the <a href='https://console.hetzner.com/projects' target='_blank'
                            class='underline dark:text-white'>Hetzner Console</a> → choose Project → Security → API
                        Tokens.
                        <br><br>
                        Don't have a Hetzner account? <a href='https://coolify.io/hetzner' target='_blank'
                            class='underline dark:text-white'>Sign up here</a>
                        <br>
                        <span class="text-xs">(Coolify's affiliate link, only new accounts - supports us (€10)
                            and gives you €20)</span>
                    </div>
                @endif
            </div>
            <x-forms.button type="submit">Validate & Add Token</x-forms.button>
        @endif
    </form>
</div>
