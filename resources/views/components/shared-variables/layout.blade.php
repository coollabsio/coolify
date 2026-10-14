<section class="application-settings-workspace w-full max-w-none">
    <header class="mb-6 xl:hidden">
        <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">Shared variables</h1>
        <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">Reusable environment variables across resources</p>
    </header>

    <div class="grid min-w-0 gap-8 xl:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        <x-shared-variables.sidebar />

        <div class="min-w-0 xl:mt-3">
            {{ $slot }}
        </div>
    </div>
</section>
