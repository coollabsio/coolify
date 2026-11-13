<?php

namespace App\Services\ProjectConfig;

enum FieldClass: string
{
    /** The file controls it. Omitted from the file means the default. */
    case Managed = 'managed';

    /** Coolify keeps it and it never appears in the file. */
    case Secret = 'secret';

    /** Coolify calculates it from other values. */
    case Derived = 'derived';

    /** Runtime data that a plan does not compare. */
    case State = 'state';
}
