<div class="w-full">
    <form class="flex flex-col gap-2 {{ $modal_mode ? 'w-full' : '' }}" wire:submit='addToken'>
        @if ($modal_mode)
            {{-- Modal layout: vertical, compact --}}
            @if (!isset($provider) || empty($provider) || $provider === '')
                <x-forms.select required id="provider" label="{{ __('forms.provider') }}">
                    <option value="hetzner">Hetzner</option>
                    <option value="digitalocean">DigitalOcean</option>
                </x-forms.select>
            @else
                <input type="hidden" wire:model="provider" />
            @endif

            <x-forms.input required id="name" label="{{ __('security.token_name') }}"
                placeholder="{{ __('forms.placeholders.cloud_token_name') }}" />

            <x-forms.input required type="password" id="token" label="{{ __('security.api_token') }}"
                placeholder="{{ __('forms.placeholders.enter_api_token') }}" />

            @if (auth()->user()->currentTeam()->cloudProviderTokens->where('provider', $provider)->isEmpty())
                <div class="text-sm text-neutral-500 dark:text-neutral-400">
                    {{ __('security.create_token_hint_prefix') }} <a
                        href='{{ $provider === 'hetzner' ? 'https://console.hetzner.com/projects' : '#' }}'
                        target='_blank' class='underline dark:text-white'>{{ ucfirst($provider) }} {{ __('security.console') }}</a> {{ __('security.token_creation_steps') }}
                    @if ($provider === 'hetzner')
                        <br><br>
                        {{ __('security.no_hetzner_account') }} <a href='https://coolify.io/hetzner' target='_blank'
                            class='underline dark:text-white'>{{ __('security.sign_up_here') }}</a>
                        <br>
                        <span class="text-xs">{{ __('security.affiliate_hint') }}</span>
                    @endif
                </div>
            @endif

            <x-forms.button type="submit">{{ __('security.validate_add_token') }}</x-forms.button>
        @else
            {{-- Full page layout: horizontal, spacious --}}
            <div class="flex gap-2 items-end flex-wrap">
                <div class="w-64">
                    <x-forms.select required id="provider" label="{{ __('forms.provider') }}" disabled>
                        <option value="hetzner" selected>Hetzner</option>
                        <option value="digitalocean">DigitalOcean</option>
                    </x-forms.select>
                </div>
                <div class="flex-1 min-w-64">
                    <x-forms.input required id="name" label="{{ __('security.token_name') }}"
                        placeholder="{{ __('forms.placeholders.cloud_token_name') }}" />
                </div>
            </div>
            <div class="flex-1 min-w-64">
                <x-forms.input required type="password" id="token" label="{{ __('security.api_token') }}"
                    placeholder="{{ __('forms.placeholders.enter_api_token') }}" />
                @if (auth()->user()->currentTeam()->cloudProviderTokens->where('provider', $provider)->isEmpty())
                    <div class="text-sm text-neutral-500 dark:text-neutral-400 mt-2">
                        {{ __('security.create_token_hint_prefix') }} <a href='https://console.hetzner.com/projects' target='_blank'
                            class='underline dark:text-white'>Hetzner {{ __('security.console') }}</a> {{ __('security.token_creation_steps') }}
                        <br><br>
                        {{ __('security.no_hetzner_account') }} <a href='https://coolify.io/hetzner' target='_blank'
                            class='underline dark:text-white'>{{ __('security.sign_up_here') }}</a>
                        <br>
                        <span class="text-xs">{{ __('security.affiliate_hint') }}</span>
                    </div>
                @endif
            </div>
            <x-forms.button type="submit">{{ __('security.validate_add_token') }}</x-forms.button>
        @endif
    </form>
</div>
