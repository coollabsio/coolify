<div x-init="$wire.loadImages">
    <div class="flex items-center gap-2">
        <h2>{{ __('application.rollback') }}</h2>
        @can('view', $application)
            <x-forms.button wire:click='loadImages(true)'>{{ __('common.reload_available_images') }}</x-forms.button>
        @endcan
    </div>
    <div class="pb-4">{{ __('application.rollback_desc') }}</div>

    @if($serverRetentionDisabled)
        <x-callout type="warning" class="mb-4">
            {{ __('application.image_retention_disabled') }}
        </x-callout>
    @endif

    <div class="pb-4">
        <form wire:submit="saveSettings" class="flex items-end gap-2 w-96">
            <x-forms.input id="dockerImagesToKeep" type="number" min="0" max="100" label="{{ __('application.images_to_keep_for_rollback') }}"
                helper="{{ __('application.images_to_keep_helper') }}"
                canGate="update" :canResource="$application" :disabled="$serverRetentionDisabled" />
            <x-forms.button canGate="update" :canResource="$application" type="submit" :disabled="$serverRetentionDisabled">{{ __('common.save') }}</x-forms.button>
        </form>
    </div>
    <div wire:target='loadImages' wire:loading.remove>
        <div class="flex flex-wrap">
            @forelse ($images as $image)
                <div class="w-2/4 p-2">
                    <div
                        class="bg-white border rounded-sm dark:border-coolgray-300 dark:bg-coolgray-100 border-neutral-200">
                        @php
                            $tag = data_get($image, 'tag');
                            $date = data_get($image, 'created_at');
                            $interval = \Illuminate\Support\Carbon::parse($date);
                            // Check if tag looks like a commit SHA (hex string) or PR tag (pr-N)
                            $isCommitSha = preg_match('/^[0-9a-f]{7,128}$/i', $tag);
                            $isPrTag = preg_match('/^pr-\d+$/', $tag);
                            $isRollbackable = $isCommitSha || $isPrTag;
                        @endphp
                        <div class="p-2">
                            <div class="">
                                @if (data_get($image, 'is_current'))
                                    <span class="font-bold dark:text-warning">LIVE</span>
                                    |
                                @endif
                                @if ($isCommitSha)
                                    SHA: {{ $tag }}
                                @elseif ($isPrTag)
                                    PR: {{ $tag }}
                                @else
                                    Tag: {{ $tag }}
                                @endif
                            </div>
                            <div class="text-xs">{{ $interval->diffForHumans() }}</div>
                            <div class="text-xs">{{ $date }}</div>
                        </div>
                        <div class="flex justify-end p-2">
                            @can('deploy', $application)
                                @if (data_get($image, 'is_current'))
                                    <x-forms.button disabled tooltip="{{ __('application.this_image_is_currently_running') }}">
                                        {{ __('common.rollback') }}
                                    </x-forms.button>
                                @elseif (!$isRollbackable)
                                    <x-forms.button disabled tooltip="Rollback not available for '{{ $tag }}' tag. Only commit-based tags support rollback. Re-deploy to create a rollback-enabled image.">
                                        {{ __('common.rollback') }}
                                    </x-forms.button>
                                @else
                                    <x-forms.button class="dark:bg-coolgray-100"
                                        wire:click="rollbackImage('{{ $tag }}')">
                                        {{ __('common.rollback') }}
                                    </x-forms.button>
                                @endif
                            @endcan
                        </div>
                    </div>
                </div>
            @empty
                <div>{{ __('common.no_images_found') }}</div>
            @endforelse
        </div>
    </div>
    <div wire:target='loadImages' wire:loading>{{ __('application.loading_available_docker_images') }}</div>
</div>