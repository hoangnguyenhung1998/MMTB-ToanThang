<?php

namespace App\Services\Reconciliation;

use Illuminate\Support\Collection;

/** Order-independent database record containers; field values and semantic lists stay intact. */
class ReconciliationRepairSnapshot
{
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        } elseif ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }
        if (! $isList) {
            ksort($value);
        } elseif ($value && count(array_filter($value, fn ($item) => is_array($item) && array_key_exists('id', $item))) === count($value)) {
            usort($value, fn ($a, $b) => ($a['id'] <=> $b['id']) ?: strcmp(serialize($a), serialize($b)));
        }

        return $value;
    }

    public static function hash(array $value): string
    {
        return hash('sha256', serialize(self::normalize($value)));
    }
}
