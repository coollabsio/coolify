<?php

it('matches project view control borders and rounding to standard buttons', function () {
    $templates = [
        resource_path('views/livewire/project/index.blade.php'),
        resource_path('views/livewire/project/show.blade.php'),
        resource_path('views/livewire/project/resource/index.blade.php'),
        resource_path('views/livewire/project/service/configuration.blade.php'),
    ];

    foreach ($templates as $template) {
        expect(file_get_contents($template))->toContain(
            'class="flex h-9 items-center rounded-lg border border-neutral-200 bg-white p-0.5 dark:border-white/[0.08] dark:bg-white/[0.06]"'
        );
    }
});
