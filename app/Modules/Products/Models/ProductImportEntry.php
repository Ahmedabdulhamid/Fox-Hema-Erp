<?php

namespace App\Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImportEntry extends Model
{
    /** MySQL JSON storage may reorder object keys; integrity must use a canonical representation. */
    public static function hashPayload(array $payload): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $normalize($item);
                }
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return $value;
        };

        return hash('sha256', json_encode($normalize($payload), JSON_THROW_ON_ERROR));
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'errors' => 'array', 'result' => 'array', 'retryable' => 'boolean'];
    }
}
