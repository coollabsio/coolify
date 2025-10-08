<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @else
        <form class="flex flex-col w-full gap-2" wire:submit='submit'>
            @if ($available_tokens->count() > 0)
                <div>
                    <x-forms.select label="Use Saved Token" id="selected_token_id" wire:change="selectToken($event.target.value)">
                        <option value="">Select a saved token...</option>
                        @foreach ($available_tokens as $token)
                            <option value="{{ $token->id }}">
                                {{ $token->name ?? 'Hetzner Token' }} (***{{ substr($token->token, -4) }})
                            </option>
                        @endforeach
                    </x-forms.select>
                </div>
                <div class="text-center text-sm dark:text-neutral-500 py-2">OR</div>
            @endif

            <div>
                <x-forms.input
                    type="password"
                    id="hetzner_token"
                    label="New Hetzner API Token"
                    helper="Your Hetzner Cloud API token. You can create one in your <a href='https://console.hetzner.cloud/' target='_blank' class='underline dark:text-white'>Hetzner Cloud Console</a>."
                />
            </div>

            <div>
                <x-forms.checkbox
                    id="save_token"
                    label="Save this token for my team"
                />
            </div>

            @if ($save_token)
                <div>
                    <x-forms.input
                        id="token_name"
                        label="Token Name"
                        placeholder="e.g., Production Hetzner"
                        helper="Give this token a friendly name to identify it later."
                    />
                </div>
            @endif

            <x-forms.button canGate="create" :canResource="App\Models\Server::class" type="submit">
                Continue
            </x-forms.button>
        </form>
    @endif
</div>
