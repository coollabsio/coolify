<div>
    <x-slot:title>
        Servers | Coolify
    </x-slot>
    <div class="flex items-start justify-between gap-2">
        <div class="flex flex-col gap-1">
            <h1>Servers</h1>
            <div class="subtitle">All your servers are here.</div>
        </div>
        @can('createAnyResource')
            <a href="{{ route('server.create') }}" {{ wireNavigate() }} class="button-primary">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                    stroke-width="2.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                New Server
            </a>
        @endcan
    </div>

    @isset($error)
        <div class="mt-4 text-center text-error">
            <span>{{ $error }}</span>
        </div>
    @endisset

    @if ($servers->count() > 0)
        <div class="overflow-hidden border rounded-xl border-neutral-200 dark:border-coolgray-300 mt-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-left uppercase bg-neutral-50 dark:bg-coolgray-200 text-neutral-500 dark:text-coolgray-500">
                        <th class="px-5 py-3 font-medium">Server</th>
                        <th class="px-5 py-3 font-medium">IP Address</th>
                        <th class="px-5 py-3 font-medium">Status</th>
                        <th class="px-5 py-3 font-medium text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-coolgray-300">
                    @foreach ($servers as $server)
                        @php
                            $unreachable = !$server->settings->is_reachable || $server->settings->force_disabled;
                            $unusable = !$server->settings->is_usable;
                        @endphp
                        <tr class="transition-colors hover:bg-neutral-50 dark:hover:bg-coolgray-200">
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <div class="resource-avatar size-8 rounded-md">
                                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 17.25v-.228a4.5 4.5 0 00-.12-1.03l-2.268-9.64a3.375 3.375 0 00-3.285-2.602H7.923a3.375 3.375 0 00-3.285 2.602l-2.268 9.64a4.5 4.5 0 00-.12 1.03v.228m19.5 0a3 3 0 01-3 3H5.25a3 3 0 01-3-3m19.5 0a3 3 0 00-3-3H5.25a3 3 0 00-3 3m16.5 0h.008v.008h-.008v-.008zm-3 0h.008v.008h-.008v-.008z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <a href="{{ route('server.show', ['server_uuid' => data_get($server, 'uuid')]) }}"
                                            {{ wireNavigate() }} class="font-medium text-black dark:text-white hover:underline">
                                            {{ $server->name }}
                                        </a>
                                        @if ($server->description)
                                            <div class="text-xs text-neutral-400 dark:text-coolgray-500">{{ $server->description }}</div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3 font-mono text-xs text-neutral-600 dark:text-coolgray-500">
                                {{ $server->ip }}
                            </td>
                            <td class="px-5 py-3">
                                @if ($unreachable || $unusable || $server->settings->force_disabled)
                                    <span class="flex items-center gap-1.5 text-xs font-medium text-error">
                                        <span class="size-1.5 rounded-full bg-error"></span>
                                        @if ($unreachable) Not reachable @endif
                                        @if ($unreachable && $unusable) & @endif
                                        @if ($unusable) Not usable @endif
                                        @if ($server->settings->force_disabled) Disabled by the system @endif
                                    </span>
                                @else
                                    <span class="flex items-center gap-1.5 text-xs font-medium text-success">
                                        <span class="size-1.5 rounded-full bg-success"></span>
                                        Reachable
                                    </span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right">
                                <a href="{{ route('server.show', ['server_uuid' => data_get($server, 'uuid')]) }}"
                                    {{ wireNavigate() }}
                                    class="text-xs font-semibold hover:underline hover:text-coollabs">
                                    Manage
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="flex flex-col items-center justify-center gap-3 p-10 mt-6 text-center border border-dashed rounded-xl border-neutral-300 dark:border-coolgray-400">
            <span class="flex items-center justify-center text-xl rounded-full size-11 bg-neutral-100 dark:bg-coolgray-200 text-neutral-400 dark:text-coolgray-500">+</span>
            <div class="font-semibold text-black dark:text-white">No servers found</div>
            <p class="max-w-sm text-sm text-neutral-500 dark:text-coolgray-500">Without a server, you won't be able to
                deploy anything.</p>
            @can('createAnyResource')
                <a href="{{ route('server.create') }}" {{ wireNavigate() }} class="button-primary">+ New Server</a>
            @endcan
        </div>
    @endif
</div>
