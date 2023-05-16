<div class="pt-4">
    <h3>Other Teams</h3>
    <div class="flex flex-col gap-2">
        @foreach (auth()->user()->otherTeams() as $team)
            <x-inputs.button isBold wire:key="{{ $team->id }}" wire:click="switch_to('{{ $team->id }}')">Switch
                to:
                {{ $team->name }}</x-inputs.button>
        @endforeach
    </div>
</div>
