<div x-data="{ deleteTeam: false }">
    <x-naked-modal show="deleteTeam" title="Delete Team"
        message='This team will be deleted. It is not reversible. <br>Please think again.' />
    <h3>Danger Zone</h3>
    <div class="pb-4">Woah. I hope you know what are you doing.</div>
    <h4 class="pb-4">Delete Team</h4>
    @if (session('currentTeam.id') === 0)
        <div>This is the default team. You can't delete it.</div>
    @elseif(auth()->user()->teams()->get()->count() === 1)
        <div>You can't delete your last team.</div>
    @else
        @if (session('currentTeam')->isEmpty())
            <div class="pb-4">This will delete your team. Beware! There is no coming back!</div>
            <x-forms.button isError x-on:click.prevent="deleteTeam = true">
                Delete
            </x-forms.button>
        @else
            <div>
                <div class="pb-4">You need to delete the following resources to be able to delete the team:</div>
                <h4 class="pb-4">Projects:</h4>
                <ul class="pl-8 list-disc">
                    @foreach (session('currentTeam')->projects as $resource)
                        <li>{{ $resource->name }}</li>
                    @endforeach
                </ul>
                <h4 class="py-4">Servers:</h4>
                <ul class="pl-8 list-disc">
                    @foreach (session('currentTeam')->servers as $resource)
                        <li>{{ $resource->name }}</li>
                    @endforeach
                </ul>
                <h4 class="py-4">Private Keys:</h4>
                <ul class="pl-8 list-disc">
                    @foreach (session('currentTeam')->privateKeys as $resource)
                        <li>{{ $resource->name }}</li>
                    @endforeach
                </ul>
                <h4 class="py-4">Sources:</h4>
                <ul class="pl-8 list-disc">
                    @foreach (session('currentTeam')->sources() as $resource)
                        <li>{{ $resource->name }}</li>
                    @endforeach
                </ul>
        @endif
    @endif

</div>
