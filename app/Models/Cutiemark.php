<?php

namespace App\Models;

use App\Traits\Sorted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Cutiemark extends Model implements HasMedia
{
    use InteractsWithMedia;

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
        $disk = $this->appearance()->first()->owner_id === null ? 'public' : 'local';
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

    public function vectorFile(): ?Media
    {
        return $this->getFirstMedia(self::CUTIEMARKS_COLLECTION);
    }
}
