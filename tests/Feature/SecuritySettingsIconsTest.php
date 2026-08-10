<?php

it('uses distinct icons for each keys and tokens navigation item', function () {
    $layout = file_get_contents(resource_path('views/components/security/settings-layout.blade.php'));
    $icons = file_get_contents(resource_path('views/components/reicon.blade.php'));

    expect($layout)
        ->toContain("'label' => 'Private Keys'", "'icon' => 'keys'")
        ->toContain("'label' => 'Cloud Tokens'", "'icon' => 'cloud'")
        ->toContain("'label' => 'Cloud-Init Scripts'", "'icon' => 'file-content'")
        ->toContain("'label' => 'API Tokens'", "'icon' => 'code'")
        ->and($icons)
        ->toContain("'cloud' =>")
        ->toContain("'code' =>");
});
