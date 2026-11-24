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
        $this->ensureSharedDirectory($root, true, 'Private import storage is unavailable.');
        $this->ensureSharedPermissions($root);
        $directory = $root.DIRECTORY_SEPARATOR.$import->uuid;
        $this->ensureSharedDirectory($directory, true, 'Private import directory is unavailable.');
        if (! str_starts_with(realpath($directory).DIRECTORY_SEPARATOR, realpath($root).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Unsafe import directory.');
        }
        $this->ensureSharedPermissions($directory);

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
        $this->ensureSharedDirectory($directory, false, 'Private parser directory is unavailable.');
        if (! str_starts_with(realpath($directory).DIRECTORY_SEPARATOR, realpath($this->directory($import)).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Unsafe parser directory.');
        }
        $this->ensureSharedPermissions($directory);

        return $directory;
    }

    private function ensureSharedDirectory(string $directory, bool $recursive, string $failure): void
    {
        if (is_link($directory) || (! is_dir($directory) && ! mkdir($directory, 0770, $recursive) && ! is_dir($directory))) {
            throw new RuntimeException($failure);
        }
    }

    private function ensureSharedPermissions(string $directory): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        clearstatcache(true, $directory);
        $permissions = fileperms($directory);
        if ($permissions === false) {
            throw new RuntimeException('Shared import directory permissions are unavailable.');
        }
        if (($permissions & 0777) === 0770) {
            return;
        }
        if (function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid()) {
            throw new RuntimeException('Shared import directory permissions require owner repair.');
        }

        $mode = ($permissions & 02000) | 0770;
        if (! chmod($directory, $mode)) {
            throw new RuntimeException('Shared import directory permissions could not be updated.');
        }
    }

    /** Exclusive writes with a durable flush; the caller retains the immutable upload on retry. */
    public function write(string $path, string $bytes): void
    {
        $stream = @fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Import file could not be allocated.');
        }
        try {
            $this->ensureSharedFilePermissions($path);
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
        if ($source === false) {
            throw new RuntimeException('Import staging failed.');
        }

        $path = $this->path($import, $name);
        $target = @fopen($path, 'xb');
        if ($target === false) {
            fclose($source);
            throw new RuntimeException('Import staging failed.');
        }
        try {
            $this->ensureSharedFilePermissions($path);
            if (stream_copy_to_stream($source, $target) !== $upload->getSize() || ! fflush($target) || ! fsync($target)) {
                throw new RuntimeException('Import staging failed.');
            }
        } finally {
            fclose($source);
            fclose($target);
        }
    }

    private function ensureSharedFilePermissions(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        clearstatcache(true, $path);
        $permissions = fileperms($path);
        if ($permissions === false) {
            throw new RuntimeException('Shared import file permissions are unavailable.');
        }
        if (($permissions & 0777) === 0660) {
            return;
        }
        if (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid()) {
            throw new RuntimeException('Shared import file permissions require owner repair.');
        }
        if (! chmod($path, 0660)) {
            throw new RuntimeException('Shared import file permissions could not be updated.');
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
