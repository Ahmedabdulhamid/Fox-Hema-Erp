<?php

namespace App\Modules\Payments\Services;

class PaymentFingerprint
{
    /** @param array<string, mixed> $payload */
    public function make(string $owner, string $operation, array $payload): string
    {
        return hash('sha256', json_encode([$owner, $operation, $this->canonicalize($payload)], JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        if (is_float($value) || is_object($value) || is_resource($value)) {
            throw new \InvalidArgumentException('Payment fingerprints accept exact scalar values and arrays only.');
        }

        return $value;
    }
}
