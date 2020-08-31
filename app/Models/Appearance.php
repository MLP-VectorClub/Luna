<?php

namespace App\Models;

use App\Enums\GuideName;
use App\Traits\HasEnumCasts;
use App\Traits\SortableTrait;
use App\Traits\Sorted;
use Illuminate\Database\Eloquent\Model;
use phpDocumentor\Reflection\Types\Boolean;
use Ramsey\Uuid\Uuid;
use Spatie\EloquentSortable\Sortable;
use Spatie\Image\Manipulations;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property GuideName $guide
 */
class Appearance extends Model implements Sortable, HasMedia
{
    use InteractsWithMedia, SortableTrait;

    const SPRITES_COLLECTION = 'sprites';
    const SPRITE_PREVIEW_CONVERSION = 'sprite-preview';
    const DOUBLE_SIZE_CONVERSION = '2x';
    const SPRITE_SIZES = [300, 600];

    public $registerMediaConversionsUsingModelInstance = true;

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

    public function registerMediaCollections(): void
    {
        $disk = $this->owner_id === null ? 'public' : 'local';
        $this->addMediaCollection(self::SPRITES_COLLECTION)
            ->singleFile()
            ->acceptsMimeTypes(['image/png'])
            ->useDisk($disk);

        $preview_convert = $this->addMediaConversion(self::SPRITE_PREVIEW_CONVERSION)
              ->keepOriginalImageFormat()
              ->fit(Manipulations::FIT_MAX, 28, 12)
              ->setManipulations(new Manipulations())
              ->performOnCollections(self::SPRITES_COLLECTION);

        $double_convert = $this->addMediaConversion(self::DOUBLE_SIZE_CONVERSION)
              ->keepOriginalImageFormat()
              ->fit(Manipulations::FIT_CONTAIN, 1400, 600)
              ->performOnCollections(self::SPRITES_COLLECTION);
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

    public function spriteFile(): ?Media
    {
        return $this->getFirstMedia(self::SPRITES_COLLECTION);
    }

    public function setNotesSrcAttribute(string $notes_src): string
    {
        # TODO Process notes
        $this->notes_rend = $notes_src;

        return $notes_src;
    }

    public function hasSprite(): bool
    {
        return (bool) $this->spriteFile();
    }

    public function getRelativeOutputPath(): string
    {
        return 'sprites';
    }
}
