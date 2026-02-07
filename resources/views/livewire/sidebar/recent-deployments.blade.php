<div wire:poll.10000ms class="mt-4 px-4 pb-4">
    <div class="flex items-center justify-between mb-2">
        <h4 class="text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-coolgray-400">
            Recent Deployments
        </h4>
    </div>
    <ul role="list" class="space-y-2">
        @forelse ($this->deployments as $deployment)
            <li>
                <a href="{{ $deployment->deployment_url }}" {{ wireNavigate() }}
                    class="group flex items-center justify-between p-2 rounded-md hover:bg-neutral-100 dark:hover:bg-coolgray-200 transition-colors">
                    <div class="flex items-center min-w-0 gap-2">
                        <div class="flex-shrink-0">
                            @if ($deployment->status === 'in_progress')
                                <svg class="w-3 h-3 text-coollabs dark:text-warning animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            @elseif ($deployment->status === 'finished')
                                <svg class="w-3 h-3 text-success" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                            @elseif ($deployment->status === 'failed')
                                <svg class="w-3 h-3 text-error" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                </svg>
                            @else
                                <svg class="w-3 h-3 text-neutral-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM11 9h4a1 1 0 110 2h-4v4a1 1 0 11-2 0v-4H5a1 1 0 110-2h4V5a1 1 0 112 0v4z" clip-rule="evenodd" />
                                </svg>
                            @endif
                        </div>
                        <div class="flex flex-col min-w-0">
                            <span class="text-[11px] font-medium text-neutral-700 dark:text-neutral-200 truncate">
                                {{ $deployment->application_name }}
                            </span>
                            <span class="text-[9px] text-neutral-500 dark:text-neutral-500 truncate">
                                {{ $deployment->created_at->diffForHumans() }}
                            </span>
                        </div>
                    </div>
                </a>
            </li>
        @empty
            <li class="text-[10px] text-neutral-400 italic px-2 pt-1">
                No recent activity
            </li>
        @endforelse
    </ul>
</div>
