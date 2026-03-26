<div>
    <x-slot:title>
        Clientes | Coolify
    </x-slot>

    <div class="flex items-center gap-2">
        <h1>Clientes</h1>
        <x-modal-input buttonTitle="+ Añadir" title="Nuevo cliente" :closeOutside="false">
            <form class="flex flex-col gap-4" wire:submit="create">
                <div class="grid gap-3">
                    <x-forms.input id="name" label="Nombre" required />
                    <x-forms.input id="email" label="Email" required />
                    <x-forms.input id="password" type="password" label="Contraseña" required />
                </div>
                <div class="flex justify-end">
                    <x-forms.button type="submit">Crear</x-forms.button>
                </div>
            </form>
        </x-modal-input>
    </div>
    <div class="subtitle">Crea clientes y asígnales servidores/proyectos.</div>

    <div class="grid gap-4 lg:grid-cols-2 -mt-1">
        @forelse ($clients as $client)
            <a href="{{ route('clients.show', ['teamId' => $client->id]) }}" {{ wireNavigate() }}
                class="gap-2 border cursor-pointer coolbox group">
                <div class="flex flex-col justify-center mx-6">
                    <div class="font-bold dark:text-white">
                        {{ $client->name }}
                    </div>
                    <div class="description">
                        ID: {{ $client->id }}
                    </div>
                </div>
                <div class="flex-1"></div>
            </a>
        @empty
            <div>No hay clientes todavía.</div>
        @endforelse
    </div>
</div>

