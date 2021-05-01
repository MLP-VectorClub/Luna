<?php

namespace App\Models;

use App\Enums\GuideName;
use App\Traits\HasEnumCasts;
use App\Traits\Sorted;
use DateInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Ramsey\Uuid\Uuid;
use SeinopSys\RGBAColor;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\Image\Manipulations;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Appearance extends Model implements Sortable, HasMedia
{
    use InteractsWithMedia, SortableTrait;

    const SPRITES_COLLECTION = 'sprites';
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
        return $this->hasMany(CutieMark::class);
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

    /**
     * @return string[]
     */
    public function getPreviewDataAttribute(): array
    {
        $delimiter = '|';
        if (App::isProduction()) {
            $cache_key = "appearance_{$this->id}_preview_data";
            $cached_data = Cache::remember(
                $cache_key,
                new DateInterval('PT1H'),
                fn () => $this->getPreviewData($delimiter)
            );
        } else {
            $cached_data = $this->getPreviewData($delimiter);
        }
        return explode($delimiter, $cached_data);
    }

    public function getIsPrivateAttribute(): bool
    {
        return $this->owner_id !== null;
    }

    protected function getPreviewData(string $delimiter): string
    {
        return Color::select(['colors.hex', 'colors.order'])
            ->leftJoin('color_groups', 'colors.group_id', '=', 'color_groups.id')
            ->where('color_groups.appearance_id', $this->id)
            ->whereNotNull('colors.hex')
            ->orderBy('color_groups.order')
            ->orderBy('colors.order')
            ->limit(4)
            ->get()
            ->map(fn (Color $c) => RGBAColor::parse($c->hex))
            ->filter(fn (?RGBAColor $c) => $c !== null)
            ->sort(fn (RGBAColor $a, RGBAColor $b) => $b->yiq() <=> $a->yiq())
            ->map(fn (RGBAColor $c) => $c->toHex())
            ->join($delimiter);
    }
}
