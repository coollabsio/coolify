<div>
    <h1>Create a new Application</h1>
    <div class="pb-4 text-sm">Deploy any public or private git repositories through a GitHub App.</div>
    @if ($github_apps->count() > 0)
        <form class="flex flex-col" wire:submit.prevent='submit'>
            <div class="flex flex-col gap-2">
                <h3 class="py-2">Select a GitHub App</h3>
                @foreach ($github_apps as $ghapp)
                    @if ($selected_github_app_id == $ghapp->id)
                        <x-forms.button class="bg-coollabs hover:bg-coollabs-100 h-7" wire:key="{{ $ghapp->id }}"
                            wire:click.prevent="loadRepositories({{ $ghapp->id }})">
                            {{ $ghapp->name }}
                        </x-forms.button>
                    @else
                        <x-forms.button wire:key="{{ $ghapp->id }}"
                            wire:click.prevent="loadRepositories({{ $ghapp->id }})">
                            {{ $ghapp->name }}
                        </x-forms.button>
                    @endif
                @endforeach
                <div class="flex flex-col">
                    @if ($repositories->count() > 0)
                        <div class="flex items-end gap-2">
                            <x-forms.select class="w-full" label="Repository URL" helper="{!! __('repository.url') !!}"
                                wire:model.defer="selected_repository_id">
                                @foreach ($repositories as $repo)
                                    @if ($loop->first)
                                        <option selected value="{{ data_get($repo, 'id') }}">
                                            {{ data_get($repo, 'name') }}
                                        </option>
                                    @else
                                        <option value="{{ data_get($repo, 'id') }}">{{ data_get($repo, 'name') }}
                                        </option>
                                    @endif
                                @endforeach
                            </x-forms.select>
                            <x-forms.button wire:click.prevent="loadBranches"> Check
                                repository</x-forms.button>
                        </div>
                    @endif
                </div>
                <div>
                    @if ($branches->count() > 0)
                        <div class="flex items-end gap-2 pb-4">
                            <x-forms.checkbox instantSave id="is_static" label="Is it a static site?"
                                helper="If your application is a static site or the final build assets should be served as a static site, enable this." />
                            <x-forms.select id="selected_branch_name" label="Branch">
                                <option value="default" disabled selected>Select a branch</option>
                                @foreach ($branches as $branch)
                                    @if ($loop->first)
                                        <option selected value="{{ data_get($branch, 'name') }}">
                                            {{ data_get($branch, 'name') }}
                                        </option>
                                    @else
                                        <option value="{{ data_get($branch, 'name') }}">
                                            {{ data_get($branch, 'name') }}
                                        </option>
                                    @endif
                                @endforeach
                            </x-forms.select>
                            @if ($is_static)
                                <x-forms.input id="publish_directory" label="Publish Directory"
                                    helper="If there is a build process involved (like Svelte, React, Next, etc..), please specify the output directory for the build assets." />
                            @else
                                <x-forms.input type="number" id="port" label="Port" :readonly="$is_static"
                                    helper="The port your application listens on." />
                            @endif
                        </div>

                        <x-forms.button type="submit">
                            Save New Application
                        </x-forms.button>
                    @endif
                </div>
            </div>
        </form>
    @else
        Add new github app
    @endif
</div>
