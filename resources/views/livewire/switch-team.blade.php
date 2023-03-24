<div>
    @foreach (auth()->user()->otherTeams() as $team)
        <button wire:key="{{ $team->id }}" wire:click="switch_to('{{ $team->id }}')">Switch to:
            {{ $team->name }}</button>
    @endforeach
</div>
