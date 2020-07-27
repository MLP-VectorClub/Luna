<?php

namespace App\Models;

use App\Enums\GuideName;
use App\Interfaces\HasStoredFiles;
use App\Traits\SortableTrait;
use App\Traits\Sorted;
use Illuminate\Database\Eloquent\Model;
use phpDocumentor\Reflection\Types\Boolean;
use Ramsey\Uuid\Uuid;
use Spatie\EloquentSortable\Sortable;

/**
 * @property GuideName $guide
 */
class Appearance extends Model implements Sortable, HasStoredFiles
{
    use SortableTrait;

    protected $fillable = [
        'order',
        'label',
        'notes_src',
        'guide',
        'private',
        'owner_id',
        'last_cleared',
        'token',
        'sprite_hash',
    ];

    protected $casts = [
        'guide' => GuideName::class,
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        self::creating(function (self $a) {
            if (!$a->token) {
                $a->token = Uuid::uuid4();
            }
        });
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function cutiemarks()
    {
        return $this->hasMany(Cutiemark::class);
    }

    public function colorGroups()
    {
        return $this->hasMany(ColorGroup::class)->orderBy('order');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'tagged');
    }

    public function spriteFile()
    {
        return $this->morphOne(UserUpload::class, 'fileable');
    }

    public function setNotesSrcAttribute(string $notes_src): string
    {
        # TODO Process notes
        $this->notes_rend = $notes_src;

        return $notes_src;
    }

    public function hasSprite(): bool
    {
        return $this->spriteFile()->exists();
    }

    public function getRelativeOutputPath(): string
    {
        return 'sprites';
    }
}
