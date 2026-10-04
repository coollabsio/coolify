<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staged rotation of the instance-wide Flux CA.
 *
 * distributing → switched → retiring → completed, or distributing → cancelling → cancelled.
 * The trust bundle that Nodes must hold follows from the status: the old and
 * new CA while distributing or switched, the new CA alone while retiring or
 * completed, and the old CA alone while cancelling or cancelled.
 */
class FluxCaRotation extends BaseModel
{
    public const STATUS_DISTRIBUTING = 'distributing';

    public const STATUS_SWITCHED = 'switched';

    public const STATUS_RETIRING = 'retiring';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLING = 'cancelling';

    public const STATUS_CANCELLED = 'cancelled';

    public const IN_PROGRESS = [
        self::STATUS_DISTRIBUTING,
        self::STATUS_SWITCHED,
        self::STATUS_RETIRING,
        self::STATUS_CANCELLING,
    ];

    protected $fillable = [
        'from_certificate_authority_id',
        'to_certificate_authority_id',
        'status',
        'dual_bundle_version',
        'final_bundle_version',
        'switch_forced',
        'completion_forced',
        'last_error',
        'started_by',
        'switched_at',
        'retirement_started_at',
        'cancelled_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'dual_bundle_version' => 'integer',
            'final_bundle_version' => 'integer',
            'switch_forced' => 'boolean',
            'completion_forced' => 'boolean',
            'switched_at' => 'immutable_datetime',
            'retirement_started_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function fromAuthority(): BelongsTo
    {
        return $this->belongsTo(FluxCertificateAuthority::class, 'from_certificate_authority_id');
    }

    public function toAuthority(): BelongsTo
    {
        return $this->belongsTo(FluxCertificateAuthority::class, 'to_certificate_authority_id');
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, self::IN_PROGRESS, true);
    }

    /**
     * The trust bundle version every Node must acknowledge before the next step.
     */
    public function targetBundleVersion(): int
    {
        return in_array($this->status, [self::STATUS_DISTRIBUTING, self::STATUS_SWITCHED], true)
            ? $this->dual_bundle_version
            : (int) $this->final_bundle_version;
    }

    /**
     * @return list<int>
     */
    public function trustedAuthorityIds(): array
    {
        return match ($this->status) {
            self::STATUS_DISTRIBUTING, self::STATUS_SWITCHED => [$this->from_certificate_authority_id, $this->to_certificate_authority_id],
            self::STATUS_RETIRING, self::STATUS_COMPLETED => [$this->to_certificate_authority_id],
            default => [$this->from_certificate_authority_id],
        };
    }
}
