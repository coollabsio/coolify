<div>
    <x-team.navbar :team="auth()->user()->currentTeam()" />
    <div class="flex items-start gap-2">
        <h2 class="pb-4">S3 Storages</h2>
        <x-modal-input buttonTitle="+ Add" title="New S3 Storage">
            <livewire:team.storage.create />
        </x-modal-input>
        {{-- <a class="dark:text-white hover:no-underline" href="/team/storages/new"> <x-forms.button>+ Add
            </x-forms.button></a> --}}
    </div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($s3 as $storage)
            <div x-data x-on:click="goto('{{ $storage->uuid }}')" @class(['gap-2 border cursor-pointer box group border-transparent'])>
                <div class="flex flex-col mx-6">
                    <div class="box-title">
                        {{ $storage->name }}
                    </div>
                    <div class="box-description">
                        {{ $storage->description }}</div>
                </div>
            </div>
        @empty
            <div>
                <div>No storage found.</div>
            </div>
        @endforelse
    </div>
    <script>
        function goto(uuid) {
            window.location.href = '/team/storages/' + uuid;
        }
    </script>
</div>
