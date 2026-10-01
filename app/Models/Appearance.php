<?php

namespace App\Models;

use App\Utils\HtmlSanitizer;
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
use Spatie\Image\Enums\Fit;
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
            ->acceptsMimeTypes(['image/png', 'image/jpeg'])
            ->useDisk($disk);

        $double_convert = $this->addMediaConversion(self::DOUBLE_SIZE_CONVERSION)
            ->keepOriginalImageFormat()
            ->fit(Fit::Contain, 1400, 600)
            ->performOnCollections(self::SPRITES_COLLECTION);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function relatedAppearances()
    {
        return $this->belongsToMany(self::class, 'related_appearances', 'source_id', 'target_id')->orderBy('target_id');
    }

    public function shows()
    {
        return $this->belongsToMany(Show::class, 'show_appearances');
    }

    /**
     * Staff can manage everything, owners can manage their own (personal guide) appearances
     */
    public function canBeManagedBy(?User $user): bool
    {
        return $user !== null && ($user->isStaff() || ($this->owner_id !== null && $this->owner_id === $user->id));
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

    /**
     * Stores the raw notes and renders them the way Winterchilla does: sanitized HTML with Derpibooru post references
     * (`>>123`) turned into links. References to shows, episodes and other appearances are left as text for now, their
     * URLs depend on the front end.
     */
    public function setNotesSrcAttribute(?string $notes_src): void
    {
        $this->attributes['notes_src'] = $notes_src;
        if ($notes_src === null) {
            $this->attributes['notes_rend'] = null;

            return;
        }

        $rendered = HtmlSanitizer::sanitize($notes_src);
        $rendered = preg_replace('/(\s)(&gt;&gt;(\d+))(\D|$)/', "$1<a href='https://derpibooru.org/$3'>$2</a>$4", $rendered);
        $this->attributes['notes_rend'] = str_replace('\#', '#', $rendered);
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
        return $cached_data === '' ? [] : explode($delimiter, $cached_data);
    }

    public function getIsPrivateAttribute(): bool
    {
        return $this->owner_id !== null;
    }

    public function getHasCutieMarksAttribute(): bool
    {
        return $this->cutiemarks()->count() !== 0;
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
