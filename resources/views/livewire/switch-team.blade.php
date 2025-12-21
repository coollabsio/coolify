<x-forms.select wire:model.live="selectedTeamId">
    <option value="default" disabled selected>{{ __('team.switch_team') }}</option>
    @foreach (auth()->user()->teams as $team)
        <option value="{{ $team->id }}">{{ $team->name }}</option>
    @endforeach
</x-forms.select>
