<?php

declare(strict_types=1);

return [

    /**
     * Add an additional second for every 100th word of the toast messages.
     *
     * Supported: true | false
     */
    'accessibility' => true,

    /**
     * The vertical alignment of the toast container.
     *
     * Supported: "bottom", "middle" or "top"
     */
    'alignment' => 'top',

    /**
     * Allow users to close toast messages prematurely.
     *
     * Supported: true | false
     */
    'closeable' => true,

    /**
     * The on-screen duration of each toast.
     *
     * Minimum: 3000 (in milliseconds)
     */
    'duration' => 3000,

    /**
     * The horizontal position of each toast.
     *
     * Supported: "center", "left" or "right"
     */
    'position' => 'right',

    /**
     * Whether messages passed as translation keys should be translated automatically.
     *
     * Supported: true | false
     */
    'translate' => true,
];
