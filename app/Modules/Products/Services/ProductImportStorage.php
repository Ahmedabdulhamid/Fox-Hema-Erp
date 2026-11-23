<?php

namespace App\Modules\Products\Services;

use App\Modules\Products\Models\ProductImport;
use App\Modules\Products\Models\ProductImportAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProductImportStorage
{
    public function directory(ProductImport $import): string
    {
        if (! Str::isUuid($import->uuid)) {
            throw new RuntimeException('Invalid import identity.');
        }
        $root = config('product-import.root');
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Private import storage is unavailable.');
        }
        $directory = $root.DIRECTORY_SEPARATOR.$import->uuid;
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Private import directory is unavailable.');
        }
        if (! str_starts_with(realpath($directory).DIRECTORY_SEPARATOR, realpath($root).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Unsafe import directory.');
        }

        return $directory;
    }

    public function path(ProductImport $import, string $name): string
    {
        if (! preg_match('/\A(?:source\.xlsx|[a-f0-9-]{36}\.(?:asset|allocation|intent|xlsx))\z/D', $name)) {
            throw new RuntimeException('Unsafe import filename.');
        }

        return $this->directory($import).DIRECTORY_SEPARATOR.$name;
    }

    public function parserDirectory(ProductImport $import): string
    {
        $directory = $this->directory($import).DIRECTORY_SEPARATOR.'parser';
        if (! is_dir($directory) && ! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('Private parser directory is unavailable.');
        }
        if (! str_starts_with(realpath($directory).DIRECTORY_SEPARATOR, realpath($this->directory($import)).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Unsafe parser directory.');
        }

        return $directory;
    }

    /** Exclusive writes with a durable flush; the caller retains the immutable upload on retry. */
    public function write(string $path, string $bytes): void
    {
        $stream = fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Import file could not be allocated.');
        }
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Import file could not be persisted.');
            }
        } finally {
            fclose($stream);
        }
    }

    public function stage(ProductImport $import, UploadedFile $upload, string $name): void
    {
        $source = fopen($upload->getRealPath(), 'rb');
        $target = fopen($this->path($import, $name), 'xb');
        if ($source === false || $target === false) {
            throw new RuntimeException('Import staging failed.');
        }
        try {
            if (stream_copy_to_stream($source, $target) !== $upload->getSize() || ! fflush($target) || ! fsync($target)) {
                throw new RuntimeException('Import staging failed.');
            }
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    public function validateImage(UploadedFile $upload): void
    {
        $size = $upload->getSize();
        $info = @getimagesize($upload->getRealPath());
        if (! $upload->isValid() || $size < 1 || $size > config('product-import.image_bytes') || $info === false
            || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || $info[0] > config('product-import.image_dimension') || $info[1] > config('product-import.image_dimension')
            || config('product-import.image_pixels') < $info[0] * $info[1]) {
            throw ValidationException::withMessages(['images' => __('product_import.invalid_image')]);
        }
        $bytes = file_get_contents($upload->getRealPath());
        if (($info['mime'] === 'image/webp' && str_contains($bytes, 'ANIM')) || ($info['mime'] === 'image/png' && str_contains($bytes, 'acTL'))) {
            throw ValidationException::withMessages(['images' => __('product_import.invalid_image')]);
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw ValidationException::withMessages(['images' => __('product_import.invalid_image')]);
        }
        imagedestroy($image);
    }

    public function asset(ProductImport $import, ProductImportAsset $asset): UploadedFile
    {
        if ((int) $asset->import_id !== (int) $import->id) {
            throw new RuntimeException('Invalid import asset owner.');
        }
        $path = $this->path($import, $asset->token.'.asset');
        if (! is_file($path) || ! hash_equals($asset->hash, hash_file('sha256', $path))) {
            throw new RuntimeException('The staged image is missing or changed.');
        }

        return new UploadedFile($path, $asset->upload_name, test: true);
    }

    public function assertSource(ProductImport $import): string
    {
        $path = $this->path($import, 'source.xlsx');
        if (! is_file($path) || ! hash_equals($import->source_hash, hash_file('sha256', $path))) {
            throw new RuntimeException('The staged workbook is missing or changed.');
        }

        return $path;
    }
}
