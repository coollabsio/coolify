<x-forms.select wire:model.live="selectedTeamId" label="Current Team">
    <option value="default" disabled selected>Switch team</option>
    @foreach (auth()->user()->teams as $team)
        <option value="{{ $team->id }}">{{ $team->name }}</option>
    @endforeach
</x-forms.select>
