@php
    $resolveStatus = function (mixed $value, bool $visible, bool $hasError): string {
        if (! $visible) {
            return 'pending';
        }

        if ($value === true) {
            return 'success';
        }

        if ($hasError) {
            return 'error';
        }

        return 'running';
    };

    $showUptime = true;
    $showOs = (bool) $uptime;
    $showPrerequisites = (bool) ($uptime && $supported_os_type);
    $showDocker = (bool) ($uptime && $supported_os_type && $prerequisites_installed);
    $showCompose = $showDocker;
    $showVersion = (bool) ($showDocker && $docker_compose_installed);
    $validationComplete = (bool) ($uptime
        && $supported_os_type
        && $prerequisites_installed
        && $docker_installed
        && $docker_compose_installed
        && $docker_version);

    $checkpoints = [
        [
            'title' => 'Server is reachable',
            'description' => 'Verify SSH connectivity and key-based authentication',
            'status' => $resolveStatus($uptime === null ? null : (bool) $uptime, $showUptime, (bool) $error && ! $uptime),
            'visible' => $showUptime,
        ],
        [
            'title' => 'Supported OS type',
            'description' => 'Confirm a supported Linux distribution',
            'status' => $resolveStatus($supported_os_type === null ? null : (bool) $supported_os_type, $showOs, (bool) $error && $showOs && ! $supported_os_type),
            'visible' => $showOs,
        ],
        [
            'title' => 'Prerequisites are installed',
            'description' => 'Install required system packages when missing',
            'status' => $resolveStatus($prerequisites_installed === null ? null : (bool) $prerequisites_installed, $showPrerequisites, (bool) $error && $showPrerequisites && ! $prerequisites_installed),
            'visible' => $showPrerequisites,
        ],
        [
            'title' => 'Docker is installed',
            'description' => 'Install or detect Docker Engine',
            'status' => $resolveStatus($docker_installed === null ? null : (bool) $docker_installed, $showDocker, (bool) $error && $showDocker && ! $docker_installed),
            'visible' => $showDocker,
        ],
        [
            'title' => 'Docker Compose is installed',
            'description' => 'Install or detect Docker Compose',
            'status' => $resolveStatus($docker_compose_installed === null ? null : (bool) $docker_compose_installed, $showCompose, (bool) $error && $showCompose && ! $docker_compose_installed),
            'visible' => $showCompose,
        ],
        [
            'title' => 'Minimum Docker version',
            'description' => 'Require Docker Engine '.str(config('constants.docker.minimum_required_version'))->before('.').' or newer',
            'status' => $resolveStatus(
                isset($docker_version) ? (bool) $docker_version : null,
                $showVersion,
                (bool) $error && $showVersion && isset($docker_version) && ! $docker_version
            ),
            'visible' => $showVersion,
        ],
    ];
@endphp

<div class="flex h-full min-h-0 flex-col gap-4 overflow-y-auto scrollbar">
    @if ($ask)
        <div
            class="rounded-[10px] border border-neutral-200 bg-neutral-50 px-4 py-3 text-[13px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
            This will revalidate the server, install or update Docker Engine, Docker Compose, and related
            configuration. Docker Engine will restart, so running containers may be briefly unreachable.
        </div>
        <x-forms.button isHighlighted wire:click="startValidatingAfterAsking">
            Continue
        </x-forms.button>
    @else
        <div data-validation-checkpoints
            class="shrink-0 overflow-hidden rounded-[10px] border border-neutral-200 dark:border-white/[0.08]">
            <div class="border-b border-neutral-200 px-4 py-2.5 dark:border-white/[0.08]">
                <h3 class="text-[13px] font-medium text-neutral-600 dark:text-fg-dim">Validation checkpoints</h3>
            </div>
            <div class="checkpoint-scroll-fade relative min-w-0" x-data="{
                observer: null,
                scrollToRunning() {
                    this.$refs.track.querySelector('[data-checkpoint-status=running]')?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' })
                }
            }"
                x-init="$nextTick(() => scrollToRunning()); observer = new MutationObserver(() => $nextTick(() => scrollToRunning())); observer.observe($refs.track, { subtree: true, childList: true, attributes: true, attributeFilter: ['data-checkpoint-status'] })"
                x-destroy="observer?.disconnect()">
                <div class="flex min-w-0 snap-x snap-mandatory overflow-x-auto overscroll-x-contain scroll-smooth scrollbar divide-x divide-neutral-200 dark:divide-white/[0.07]"
                    x-ref="track">
                    @foreach ($checkpoints as $checkpoint)
                        <x-checkpoint-item :title="$checkpoint['title']" :description="$checkpoint['description']"
                            :status="$checkpoint['status']" data-checkpoint-status="{{ $checkpoint['status'] }}"
                            class="basis-[88%] shrink-0 snap-start sm:basis-72 lg:basis-80" />
                    @endforeach
                </div>
            </div>
        </div>

        @if ($validationComplete)
            <div class="mt-auto flex shrink-0 items-center justify-between gap-3 rounded-[10px] border border-emerald-500/20 bg-emerald-500/[0.06] px-4 py-3">
                <div class="flex items-center gap-2 text-[13px] font-medium text-emerald-700 dark:text-emerald-300">
                    <x-reicon name="check-circle" class="size-4 shrink-0" />
                    Validation complete
                </div>
                <x-forms.button type="button" @click="processDialogOpen = false">
                    Close
                </x-forms.button>
            </div>
        @elseif ($isInstalling)
            <section class="application-settings-section validation-installation-logs">
                <div class="application-settings-section-body">
                    <livewire:activity-monitor :header="$installationStep.' installation logs'" :showWaiting="false" />
                </div>
            </section>
        @endif

        @isset($error)
            <div
                class="rounded-[10px] border border-red-500/20 bg-red-500/[0.06] px-4 py-3 text-[13px] leading-5 text-red-700 dark:text-red-300">
                <div class="mb-1 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-[0.06em]">
                    <x-reicon name="alert-circle" class="size-3.5 shrink-0" />
                    Validation failed
                </div>
                <div class="font-mono text-[12px] leading-5 whitespace-pre-line">{!! $error !!}</div>
            </div>
            <x-forms.button canGate="update" :canResource="$server" wire:click="retry">
                <x-reicon name="refresh" class="size-3.5" />
                Retry validation
            </x-forms.button>
        @endisset
    @endif
</div>
