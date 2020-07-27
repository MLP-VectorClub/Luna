<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;

class UserUpload extends Model
{
    protected $fillable = [
        'fileable',
        'uploader_id',
        'name',
        'path',
        'size',
        'hash',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        static::deleted(function (self $file) {
            $file_path = storage_path($file->path);

            if (File::exists($file_path)) {
                File::delete($file_path);
            }
        });
    }

    public function fileable()
    {
        return $this->morphTo();
    }
}
