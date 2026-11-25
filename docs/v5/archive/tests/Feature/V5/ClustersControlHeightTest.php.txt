<?php

it('keeps the cluster selector and add button the same height', function () {
    $clustersPage = file_get_contents(resource_path('js/v5/Pages/Clusters.tsx'));

    expect($clustersPage)->toContain('<SelectTrigger')
        ->and($clustersPage)->toContain('className="w-full sm:w-72"')
        ->and($clustersPage)->toContain('variant="coolify"')
        ->and($clustersPage)->toContain('size="default"')
        ->and($clustersPage)->not->toContain('variant="coolify"\n                                        size="sm"');
});
