<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OpenApi\Annotations as OA;

class Post extends Model
{
    public const KINDS = ['request', 'reservation'];
    public const REQUEST_TYPES = ['chr' => 'Characters', 'obj' => 'Objects', 'bg' => 'Backgrounds'];

    // The table has an updated_at column but no created_at, requests and reservations carry their own timestamps
    public const CREATED_AT = null;

    protected $fillable = [
        'type',
        'preview',
        'fullsize',
        'label',
        'requested_by',
        'requested_at',
        'reserved_by',
        'reserved_at',
        'deviation_id',
        'lock',
        'finished_at',
        'broken',
        'show_id',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'reserved_at' => 'datetime',
        'finished_at' => 'datetime',
        'lock' => 'boolean',
        'broken' => 'boolean',
    ];

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reserver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by');
    }

    public function isRequest(): bool
    {
        return $this->requested_by !== null;
    }

    public function kind(): string
    {
        return $this->isRequest() ? 'request' : 'reservation';
    }

    /** Requests are posted by their requester, reservations by whoever reserved them */
    public function poster(): ?User
    {
        return $this->isRequest() ? $this->requester : $this->reserver;
    }

    public function postedAt(): ?CarbonInterface
    {
        return $this->isRequest() ? $this->requested_at : $this->reserved_at;
    }

    public function isFinished(): bool
    {
        return $this->deviation_id !== null && $this->reserved_by !== null;
    }

    /** A request that has been reserved for over 3 weeks without a finished deviation can be taken over */
    public function isOverdue(): bool
    {
        return $this->isRequest() && $this->deviation_id === null && $this->reserved_by !== null
            && $this->reserved_at !== null && $this->reserved_at->diffInSeconds(now(), false) >= 3 * 7 * 86400;
    }

    public function canBeEditedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->isStaff()
            || ($this->isRequest() && $this->reserved_by === null && $this->requested_by === $user->id)
            || (!$this->isRequest() && $this->reserved_by === $user->id);
    }

    /**
     * @OA\Schema(
     *   schema="PostItem",
     *   description="A request or reservation on a show's page, as data",
     *   type="object",
     *   required={"id", "kind", "showId", "label", "previewUrl", "fullsizeUrl", "postedAt", "postedBy", "reservedBy", "reservedAt", "finishedAt", "deviationId", "approved", "broken", "overdue", "canEdit"},
     *   additionalProperties=false,
     *   @OA\Property(property="id", ref="#/components/schemas/OneBasedId"),
     *   @OA\Property(property="kind", type="string", enum={"request", "reservation"}),
     *   @OA\Property(property="type", type="string", enum={"chr", "obj", "bg"}, description="What the request asks for; requests only"),
     *   @OA\Property(property="showId", ref="#/components/schemas/OneBasedId"),
     *   @OA\Property(property="label", type="string"),
     *   @OA\Property(property="previewUrl", type="string"),
     *   @OA\Property(property="fullsizeUrl", type="string"),
     *   @OA\Property(property="postedAt", type="string", format="date-time"),
     *   @OA\Property(property="postedBy", ref="#/components/schemas/PostUser", nullable=true),
     *   @OA\Property(property="reservedBy", ref="#/components/schemas/PostUser", nullable=true),
     *   @OA\Property(property="reservedAt", type="string", format="date-time", nullable=true),
     *   @OA\Property(property="finishedAt", type="string", format="date-time", nullable=true, description="Set once the post has a deviation"),
     *   @OA\Property(property="deviationId", type="string", nullable=true, description="ID of the finished deviation (https://fav.me/{id})"),
     *   @OA\Property(property="approved", type="boolean", description="Whether the finished post has been accepted to the club gallery"),
     *   @OA\Property(property="broken", type="boolean", description="Whether the image was deemed unavailable; only staff see broken posts"),
     *   @OA\Property(property="overdue", type="boolean", description="Reserved and unfinished for over 3 weeks, so others may reserve it"),
     *   @OA\Property(property="canEdit", type="boolean", description="Whether the current user may edit the post")
     * )
     * @OA\Schema(
     *   schema="PostUser",
     *   type="object",
     *   required={"id", "name"},
     *   additionalProperties=false,
     *   @OA\Property(property="id", ref="#/components/schemas/OneBasedId"),
     *   @OA\Property(property="name", type="string")
     * )
     */
    public function toContract(?User $viewer): array
    {
        $user = fn(?User $u) => $u === null ? null : ['id' => $u->id, 'name' => $u->name];
        $iso = fn(?CarbonInterface $time) => $time?->toIso8601String();

        $result = [
            'id' => $this->id,
            'kind' => $this->kind(),
            'showId' => $this->show_id,
            'label' => $this->label,
            'previewUrl' => $this->preview,
            'fullsizeUrl' => $this->fullsize,
            'postedAt' => $iso($this->postedAt()),
            'postedBy' => $user($this->poster()),
            'reservedBy' => $user($this->reserver),
            'reservedAt' => $iso($this->reserved_at),
            'finishedAt' => $iso($this->finished_at),
            'deviationId' => $this->deviation_id,
            'approved' => $this->lock,
            'broken' => $this->broken,
            'overdue' => $this->isOverdue(),
            'canEdit' => $this->canBeEditedBy($viewer),
        ];
        if ($this->isRequest()) {
            $result['type'] = $this->type;
        }

        return $result;
    }
}
