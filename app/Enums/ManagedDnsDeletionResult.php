<?php

namespace App\Enums;

enum ManagedDnsDeletionResult: string
{
    /** The provider record was deleted. */
    case Deleted = 'deleted';

    /** The provider record no longer exists. */
    case AlreadyGone = 'already_gone';

    /** Coolify did not create the record, so it is never deleted. */
    case NotOwned = 'not_owned';

    /** The record no longer carries Coolify's ownership comment or its value changed. */
    case ChangedExternally = 'changed_externally';

    /** The provider could not be reached or rejected the request; retry later. */
    case Failed = 'failed';

    /** Another change for the same hostname held the hostname lock; nothing was changed, retry later. */
    case Busy = 'busy';

    public function removedFromProvider(): bool
    {
        return $this === self::Deleted || $this === self::AlreadyGone;
    }

    public function shouldRetry(): bool
    {
        return $this === self::Failed || $this === self::Busy;
    }
}
