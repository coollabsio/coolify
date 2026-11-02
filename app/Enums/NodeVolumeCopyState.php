<?php

namespace App\Enums;

/** The state of a copy of a volume on a Node other than the Node of the volume. */
enum NodeVolumeCopyState: string
{
    /** A stateful move copies the data to this Node now. */
    case RECEIVING = 'receiving';

    /** The source data of a finished move, kept until `delete_after`. */
    case RETAINED = 'retained';
}
