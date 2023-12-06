<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Include CSS
    |--------------------------------------------------------------------------
    |
    | The modal uses TailwindCSS, if you don't use TailwindCSS you will need
    | to set this parameter to true. This includes the modern-normalize css.
    |
    */
    'include_css' => false,

    /*
    |--------------------------------------------------------------------------
    | Include JS
    |--------------------------------------------------------------------------
    |
    | Livewire UI will inject the required Javascript in your blade template.
    | If you want to bundle the required Javascript you can set this to false
    | and add `require('vendor/wire-elements/modal/resources/js/modal');`
    | to your script bundler like webpack.
    |
    */
    'include_js' => true,

    /*
    |--------------------------------------------------------------------------
    | Modal Component Defaults
    |--------------------------------------------------------------------------
    |
    | Configure the default properties for a modal component.
    |
    | Supported modal_max_width
    | 'sm', 'md', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl', '6xl', '7xl'
    */
    'component_defaults' => [
        'modal_max_width' => '2xl',

        'close_modal_on_click_away' => true,

        'close_modal_on_escape' => true,

        'close_modal_on_escape_is_forceful' => true,

        'dispatch_close_event' => false,

        'destroy_on_close' => false,
    ],
];
