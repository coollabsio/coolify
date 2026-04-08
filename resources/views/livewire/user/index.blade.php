<div>
    <x-slot:title>
        Usuarios | Coolify
    </x-slot>

    <div class="pb-6">
        <div class="flex items-end gap-2">
            <h1>Usuarios</h1>
            <a class="button" href="{{ route('users.create') }}" {{ wireNavigate() }}>+ Nuevo usuario</a>
        </div>
        <div class="subtitle">Gestiona los usuarios que pueden iniciar sesión y los proyectos a los que tienen acceso.</div>
    </div>

    <div class="flex flex-col">
        <div class="overflow-x-auto">
            <div class="inline-block min-w-full">
                <div class="overflow-hidden">
                    <table class="min-w-full">
                        <thead>
                            <tr>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Nombre</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Email</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Tipo</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Proyectos asignados</th>
                                <th class="px-5 py-3 text-xs font-medium text-left uppercase">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($users as $user)
                                <tr class="text-xs">
                                    <td class="px-5 py-4 whitespace-nowrap">{{ $user->name }}</td>
                                    <td class="px-5 py-4 whitespace-nowrap">{{ $user->email }}</td>
                                    <td class="px-5 py-4 whitespace-nowrap">
                                        @if ($user->is_client)
                                            <span class="px-2 py-1 rounded bg-coolgray-200 dark:bg-coolgray-300">Cliente</span>
                                        @else
                                            <span class="px-2 py-1 rounded bg-coollabs/20">Interno</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4">
                                        @if ($user->assignedProjects->isEmpty())
                                            <span class="text-neutral-500">—</span>
                                        @else
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($user->assignedProjects as $project)
                                                    <span class="px-2 py-1 rounded bg-coolgray-200 dark:bg-coolgray-300">{{ $project->name }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap">
                                        @can('update', $user)
                                            <a class="hover:underline" href="{{ route('users.edit', ['user' => $user->id]) }}" {{ wireNavigate() }}>Editar</a>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-5 py-4 text-center text-neutral-500">No hay usuarios todavía.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
