<div>
    <x-slot:title>
        {{ __('teams.title') }}
    </x-slot>
    <x-team.navbar />

    <form class="flex flex-col" wire:submit='submit'>
        <h2>{{ __('teams.general') }}</h2>
        <div class="subtitle">
            {{ __('teams.general_subtitle') }}
        </div>

        <div class="flex items-end gap-2 pb-6">
            <x-forms.input id="name" label="{{ __('input.name') }}" required canGate="update" :canResource="$team" />
            <x-forms.input id="description" label="{{ __('teams.description') }}" canGate="update" :canResource="$team" />
            @can('update', $team)
                <x-forms.button type="submit">
                    {{ __('button.save') }}
                </x-forms.button>
            @endcan
        </div>
    </form>

    @can('delete', $team)
        <div>
            <h2>{{ __('teams.danger_zone') }}</h2>
            <div class="pb-4">{{ __('teams.danger_warning') }}</div>
            <h4 class="pb-4">{{ __('teams.delete_team') }}</h4>
            @if (session('currentTeam.id') === 0)
                <div>{{ __('teams.default_team_warning') }}</div>
            @elseif(auth()->user()->teams()->get()->count() === 1 || auth()->user()->currentTeam()->personal_team)
                <div>{{ __('teams.last_team_warning') }}</div>
            @elseif(currentTeam()->subscription)
                <div>{{ __('teams.cancel_subscription_first') }} <a class="underline dark:text-white"
                        {{ wireNavigate() }}
                        href="{{ route('subscription.show') }}">{{ __('teams.here') }}</a> {{ __('teams.before_deleting') }}</div>
            @else
                @if (currentTeam()->isEmpty())
                    <div class="pb-4">{{ __('teams.delete_warning') }}</div>
                    <x-modal-confirmation title="{{ __('teams.confirm_delete') }}" buttonTitle="{{ __('button.delete') }}" isErrorButton
                        submitAction="delete({{ currentTeam()->id }})" :actions="[__('teams.delete_action_warning')]"
                        confirmationText="{{ currentTeam()->name }}"
                        confirmationLabel="{{ __('teams.confirm_label') }}"
                        shortConfirmationLabel="{{ __('teams.team_name') }}" :confirmWithPassword="false" step2ButtonText="{{ __('teams.permanently_delete') }}" />
                @else
                    <div>
                        <div class="pb-4">{{ __('teams.delete_resources_first') }}</div>
                        @if (currentTeam()->projects()->count() > 0)
                            <h4 class="pb-4">{{ __('teams.projects') }}:</h4>
                            <ul class="pl-8 list-disc">
                                @foreach (currentTeam()->projects as $resource)
                                    <li>{{ $resource->name }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if (currentTeam()->servers()->count() > 0)
                            <h4 class="py-4">{{ __('teams.servers') }}:</h4>
                            <ul class="pl-8 list-disc">
                                @foreach (currentTeam()->servers as $resource)
                                    <li>{{ $resource->name }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if (currentTeam()->privateKeys()->count() > 0)
                            <h4 class="py-4">{{ __('teams.private_keys') }}:</h4>
                            <ul class="pl-8 list-disc">
                                @foreach (currentTeam()->privateKeys as $resource)
                                    <li>{{ $resource->name }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if (currentTeam()->sources()->count() > 0)
                            <h4 class="py-4">{{ __('teams.sources') }}:</h4>
                            <ul class="pl-8 list-disc">
                                @foreach (currentTeam()->sources() as $resource)
                                    <li>{{ $resource->name }}</li>
                                @endforeach
                            </ul>
                        @endif
                @endif
            @endif
        </div>
    @endcan
</div>
