<?php

namespace App\Services;

use App\Models\Image;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageStorageService
{
    public function upload(Model $entity, UploadedFile $file, bool $isPrimary = false, ?string $disk = null): Image
    {
        $disk = $disk ?: config('filesystems.images_disk', 's3');
        $folder = 'images/'.Str::snake(Str::plural(class_basename($entity))).'/'.$entity->getKey();
        $extension = $file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin';
        $filename = Str::uuid()->toString().'.'.$extension;

        $path = $file->storeAs($folder, $filename, ['disk' => $disk]);

        $url = null;
        try {
            $url = Storage::disk($disk)->url($path);
        } catch (\Throwable) {
            $url = null;
        }

        return DB::transaction(function () use ($entity, $file, $disk, $path, $url, $isPrimary) {
            $existingCount = Image::query()
                ->where('imageable_type', $entity->getMorphClass())
                ->where('imageable_id', $entity->getKey())
                ->count();

            $shouldBePrimary = $isPrimary || $existingCount === 0;

            if ($shouldBePrimary) {
                Image::query()
                    ->where('imageable_type', $entity->getMorphClass())
                    ->where('imageable_id', $entity->getKey())
                    ->update(['is_primary' => false]);
            }

            $maxSort = Image::query()
                ->where('imageable_type', $entity->getMorphClass())
                ->where('imageable_id', $entity->getKey())
                ->max('sort_order') ?? 0;

            return Image::create([
                'imageable_type' => $entity->getMorphClass(),
                'imageable_id' => $entity->getKey(),
                'disk' => $disk,
                'bucket' => config("filesystems.disks.{$disk}.bucket"),
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
                'size' => $file->getSize(),
                'url' => $url,
                'is_primary' => $shouldBePrimary,
                'sort_order' => $maxSort + 1,
            ]);
        });
    }

    public function delete(Image $image): bool
    {
        return DB::transaction(function () use ($image) {
            $wasPrimary = $image->is_primary;
            $type = $image->imageable_type;
            $id = $image->imageable_id;

            $image->deleteFromDisk();
            $deleted = $image->delete();

            if ($wasPrimary) {
                $next = Image::query()
                    ->where('imageable_type', $type)
                    ->where('imageable_id', $id)
                    ->orderBy('sort_order')
                    ->orderBy('created_at')
                    ->first();

                $next?->update(['is_primary' => true]);
            }

            return (bool) $deleted;
        });
    }

    public function setPrimary(Image $image): Image
    {
        return DB::transaction(function () use ($image) {
            Image::query()
                ->where('imageable_type', $image->imageable_type)
                ->where('imageable_id', $image->imageable_id)
                ->update(['is_primary' => false]);

            $image->update(['is_primary' => true]);

            return $image->refresh();
        });
    }
}

