<?php

it('does not render server capability summaries on the cluster page', function () {
    $clustersPage = file_get_contents(resource_path('js/v5/Pages/Clusters.tsx'));

    expect($clustersPage)
        ->not->toContain('Capabilities:')
        ->not->toContain('normalizeCapabilities');
});
