<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Set `ensure_pages_exist` to true if you want to enforce that Inertia page
    | components exist on disk when rendering a page. This is useful for
    | catching missing or misnamed components.
    |
    | The `page_paths` and `page_extensions` options define where to look
    | for page components and which file extensions to consider.
    |
    */

    'ensure_pages_exist' => true,

    'page_paths' => [
        resource_path('js/pages'),
    ],

    'page_extensions' => [
        'svelte',
        'ts',
    ],

    'use_script_element_for_initial_page' => (bool) env('INERTIA_USE_SCRIPT_ELEMENT_FOR_INITIAL_PAGE', true),

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | The values described here are used to locate Inertia components on the
    | filesystem. For instance, when using `assertInertia`, the assertion
    | attempts to locate the component as a file relative to any of the
    | paths AND with any of the extensions specified here.
    |
    | Note: In a future release, the `page_paths` and `page_extensions`
    | options below will be removed. The root-level options above
    | will be used for both application and testing purposes.
    |
    */

    'testing' => [
        'ensure_pages_exist' => true,

        'page_paths' => [
            resource_path('js/pages'),
        ],

        'page_extensions' => [
            'svelte',
            'ts',
        ],
    ],

    'history' => [
        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', true),
    ],
];
