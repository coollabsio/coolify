<div>
    <h1>Create a new Application</h1>
    <div class="pt-2">Deploy any public or private Git repositories through a Deploy Key.</div>
    <div class="flex flex-col pt-10">
        @if ($current_step === 'private_keys')
            <ul class="pb-10 steps">
                <li class="step step-secondary">Select a Private Key</li>
                <li class="step">Select a Repository, Branch & Save</li>
            </ul>
            <div class="flex flex-col justify-center gap-2 text-left xl:flex-row">
                @foreach ($private_keys as $key)
                    @if ($private_key_id == $key->id)
                        <div class="gap-2 py-4 cursor-pointer group hover:bg-coollabs bg-coolgray-200"
                            wire:click.defer="setPrivateKey('{{ $key->id }}')" wire:key="{{ $key->id }}">
                            <div class="flex gap-4 mx-6">
                                <div class="group-hover:text-white">
                                    {{ $key->name }}
                                </div>
                                <span wire:target="loadRepositories" wire:loading.delay
                                    class="loading loading-xs text-warning loading-spinner"></span>
                            </div>
                        </div>
                    @else
                        <div class="gap-2 py-4 cursor-pointer group hover:bg-coollabs bg-coolgray-200"
                            wire:click.defer="setPrivateKey('{{ $key->id }}')" wire:key="{{ $key->id }}">
                            <div class="flex gap-4 mx-6">
                                <div class="group-hover:text-white">
                                    {{ $key->name }}
                                </div>
                                <span wire:target="loadRepositories" wire:loading.delay
                                    class="loading loading-xs text-warning loading-spinner"></span>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
        @if ($current_step === 'repository')
            <ul class="pb-10 steps">
                <li class="step step-secondary">Select a Private Key</li>
                <li class="step step-secondary">Select a Repository, Branch & Save</li>
            </ul>
            <form class="flex flex-col gap-2 pb-6" wire:submit.prevent='submit'>
                <div class="flex gap-2">
                    <x-forms.input id="repository_url" required label="Repository URL"
                        helper="{!! __('repository.url') !!}" />
                    <x-forms.input id="branch" required label="Branch" />
                    @if ($is_static)
                        <x-forms.input id="publish_directory" required label="Publish Directory" />
                    @else
                        <x-forms.input type="number" required id="port" label="Port" :readonly="$is_static" />
                    @endif
                </div>
                <div class="w-52">
                    <x-forms.checkbox instantSave id="is_static" label="Is it a static site?"
                        helper="If your application is a static site or the final build assets should be served as a static site, enable this." />
                </div>
                <x-forms.button type="submit">
                    Save New Application
                </x-forms.button>
            </form>
        @endif
    </div>
</div>
