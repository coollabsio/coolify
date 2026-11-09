@if (empty($changes))
    <p class="text-[13px] text-neutral-500 dark:text-fg-dim">No changes.</p>
@else
    <table class="w-full text-left text-[13px]">
        <thead class="text-neutral-500 dark:text-fg-dim">
            <tr><th class="py-1">Resource</th><th>Change</th><th>Setting</th><th>Value</th></tr>
        </thead>
        <tbody>
            @foreach ($changes as $index => $change)
                <tr wire:key="change-{{ $change['key'] }}-{{ $change['kind'] }}-{{ $change['path'] }}" class="border-t border-neutral-200 dark:border-coolgray-300">
                    <td class="py-1.5"><code>{{ $change['key'] }}</code></td>
                    <td>
                        {{ $change['kind'] }}
                        @if ($change['destructive'])
                            <span class="ml-1 rounded px-1 text-[11px] font-medium text-red-600 ring-1 ring-red-500/40">destructive</span>
                        @endif
                    </td>
                    <td><code>{{ $change['path'] ?: '-' }}</code></td>
                    <td class="break-all">
                        @if ($change['secret'])
                            changed
                        @elseif (! in_array($change['kind'], ['update'], true))
                            {{ is_scalar($change['to'] ?? null) ? $change['to'] : '' }}
                        @else
                            {{ is_scalar($change['from']) ? $change['from'] : json_encode($change['from']) }} &rarr; {{ is_scalar($change['to']) ? $change['to'] : json_encode($change['to']) }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
