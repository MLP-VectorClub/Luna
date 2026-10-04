<?php

namespace App\Models;

use App\Traits\Sorted;
use App\Utils\SvgHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class CutieMark extends Model implements HasMedia
{
    // The table has no created_at / updated_at columns
    public $timestamps = false;

    use InteractsWithMedia;

    protected $table = 'cutiemarks';

    const CUTIEMARKS_COLLECTION = 'cutiemarks';

    public $registerMediaConversionsUsingModelInstance = true;

    protected $fillable = [
        'appearance_id',
        'facing',
        'favme',
        'rotation',
        'contributor_id',
        'label',
    ];

    public function registerMediaCollections(): void
    {
        /** @var Appearance $appearance */
        $appearance = $this->appearance()->first();
        $disk = $appearance->is_private ? 'local' : 'public';
        $this->addMediaCollection(self::CUTIEMARKS_COLLECTION)
            ->singleFile()
            ->acceptsMimeTypes(['image/svg','image/svg+xml'])
            ->useDisk($disk);

        // TODO Use an event for further transformations
        // https://docs.spatie.be/laravel-medialibrary/v8/advanced-usage/consuming-events/
    }

    public function appearance(): BelongsTo
    {
        return $this->belongsTo(Appearance::class);
    }

    public function contributor(): BelongsTo
    {
        return $this->belongsTo(DeviantartUser::class, 'contributor_id');
    }

    /** Custom property of the media holding the SVG with its colors replaced by tokens that point at the guide (`SvgHelper::tokenize`) */
    const TOKENIZED_PROPERTY = 'tokenized';

    const COLOR_GROUP_LABEL = 'Cutie Mark';

    /**
     * @return array<int, string> color ID => hex of the appearance's Cutie Mark color group
     */
    public static function guideColors(int $appearance_id): array
    {
        $group = ColorGroup::where('appearance_id', $appearance_id)->where('label', self::COLOR_GROUP_LABEL)->first();

        return $group === null ? [] : Color::where('group_id', $group->id)->whereNotNull('hex')->pluck('hex', 'id')->all();
    }

    /**
     * Stores the (sanitized) SVG as the cutie mark's file: the colors that the guide knows are kept as tokens next to the file so
     * `rerender` can follow later color changes, and the served file holds the colors as they are now.
     *
     * @param  array<string, mixed>  $properties  custom properties of the media, e.g. who uploaded it
     */
    public function storeSvg(string $svg, array $properties = []): void
    {
        $colors = self::guideColors($this->appearance_id);
        if ($colors !== []) {
            $tokenized = SvgHelper::tokenize($svg, $colors);
            $svg = SvgHelper::untokenize($tokenized, $colors);
            $properties[self::TOKENIZED_PROPERTY] = $tokenized;
        }

        $this->addMediaFromString($svg)
            ->usingFileName(sha1($svg).'.svg')
            ->withCustomProperties($properties)
            ->toMediaCollection(self::CUTIEMARKS_COLLECTION);
    }

    /**
     * Writes the file again with the guide's current colors. The file name follows the content, so the new address is not cached.
     * Cutie marks stored without tokens (no Cutie Mark color group at the time) are left alone.
     */
    public function rerender(): void
    {
        $media = $this->vectorFile();
        $tokenized = $media?->getCustomProperty(self::TOKENIZED_PROPERTY);
        if ($tokenized === null) {
            return;
        }

        $svg = SvgHelper::untokenize($tokenized, self::guideColors($this->appearance_id));
        if ($svg === file_get_contents($media->getPath())) {
            return;
        }
        $properties = $media->custom_properties;
        $this->addMediaFromString($svg)
            ->usingFileName(sha1($svg).'.svg')
            ->withCustomProperties($properties)
            ->toMediaCollection(self::CUTIEMARKS_COLLECTION);
    }

    /** Called when the colors of an appearance's Cutie Mark color group changed */
    public static function rerenderAllOf(int $appearance_id): void
    {
        foreach (self::where('appearance_id', $appearance_id)->get() as $cutie_mark) {
            $cutie_mark->rerender();
        }
    }

    public function vectorFile(): ?Media
    {
        return $this->getFirstMedia(self::CUTIEMARKS_COLLECTION);
    }
}
