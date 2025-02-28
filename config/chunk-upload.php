<?php

/**
 * @see https://github.com/pionl/laravel-chunk-upload
 */

return [
    /*
     * The storage config
     */
    'storage' => [
        /*
         * Returns the folder name of the chunks. The location is in storage/app/{folder_name}
         */
        'chunks' => 'chunks',
        'disk' => 'local',
    ],
    'clear' => [
        /*
         * How old chunks we should delete
         */
        'timestamp' => '-1 HOURS',
        'schedule' => [
            'enabled' => false,
            'cron' => '25 * * * *', // run every hour on the 25th minute
        ],
    ],
    'chunk' => [
        // setup for the chunk naming setup to ensure same name upload at same time
        'name' => [
            'use' => [
                'session' => true, // should the chunk name use the session id? The uploader must send cookie!,
                'browser' => false, // instead of session we can use the ip and browser?
            ],
        ],
    ],
    'handlers' => [
        // A list of handlers/providers that will be appended to existing list of handlers
        'custom' => [],
        // Overrides the list of handlers - use only what you really want
        'override' => [
            // \Pion\Laravel\ChunkUpload\Handler\DropZoneUploadHandler::class
        ],
    ],
];
