<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Image extends BaseModel
{
    protected $fillable = [
        'imageable_type',
        'imageable_id',
        'disk',
        'bucket',
        'path',
        'original_name',
        'mime_type',
        'size',
        'url',
        'is_primary',
        'sort_order',
        'active',
    ];

    protected $appends = [
        'url',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
            'size' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function imageable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getUrlAttribute(): ?string
    {
        if ($this->path) {
            try {
                $disk = $this->disk ?: config('filesystems.images_disk', 's3');

                return Storage::disk($disk)->url($this->path);
            } catch (\Throwable) {
                // fall back to stored attribute
            }
        }

        if (! empty($this->attributes['url'])) {
            return $this->attributes['url'];
        }

        return null;
    }

    public function deleteFromDisk(): bool
    {
        if (! $this->path) {
            return false;
        }

        try {
            $disk = $this->disk ?: config('filesystems.images_disk', 's3');

            return Storage::disk($disk)->delete($this->path);
        } catch (\Throwable) {
            return false;
        }
    }
}
