<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @else
        <form class="flex flex-col w-full gap-2" wire:submit='submit'>
            <div class="flex w-full gap-2 flex-wrap sm:flex-nowrap">
                <x-forms.input id="name" label="{{ __('common.name') }}" required />
                <x-forms.input id="description" label="{{ __('common.description') }}" />
            </div>
            <div class="flex gap-2 flex-wrap sm:flex-nowrap">
                <x-forms.input id="ip" label="{{ __('server.ip_address_domain') }}" required
                    helper="{{ __('server.ip_address_domain_helper') }}" />
                <x-forms.input type="number" id="port" label="{{ __('server.port') }}" required />
            </div>
            <x-forms.input id="user" label="{{ __('server.user') }}" required />
            <div class="text-xs dark:text-warning text-coollabs ">{{ __('server.non_root_user_experimental') }} <a
                    class="font-bold underline" target="_blank"
                    href="https://coolify.io/docs/knowledge-base/server/non-root-user">{{ __('menu.documentation') }}</a>.</div>
            <x-forms.select label="{{ __('server.private_key') }}" id="private_key_id">
                <option disabled>{{ __('server.select_private_key_option') }}</option>
                @foreach ($private_keys as $key)
                    @if ($loop->first)
                        <option selected value="{{ $key->id }}">{{ $key->name }}</option>
                    @else
                        <option value="{{ $key->id }}">{{ $key->name }}</option>
                    @endif
                @endforeach
            </x-forms.select>
            <div class="">
                <x-forms.checkbox instantSave type="checkbox" id="is_build_server"
                    helper="{{ __('server.build_server_helper') }}"
                    label="{{ __('server.use_as_build_server') }}" />
            </div>
            <x-forms.button type="submit">
                {{ __('common.continue') }}
            </x-forms.button>
        </form>
    @endif
</div>