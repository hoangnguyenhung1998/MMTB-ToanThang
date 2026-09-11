<?php

namespace App\Services;

use App\Models\Machine;
use Illuminate\Support\Str;

class AssetCodeResolver
{
    private ?array $index = null;

    public function resolve(mixed $candidate): array
    {
        $observed = $this->observed($candidate);
        if ($observed === null) {
            return $this->result('MISSING', null, null, collect());
        }

        $key = self::canonicalKey($observed);
        if ($key === null) {
            return $this->result('NOT_FOUND', $observed, null, collect());
        }

        $matches = collect($this->machineIndex()[$key] ?? []);

        return match ($matches->count()) {
            0 => $this->result('NOT_FOUND', $observed, $key, $matches),
            1 => $this->result('MATCHED', $observed, $key, $matches),
            default => $this->result('AMBIGUOUS', $observed, $key, $matches),
        };
    }

    public static function canonicalKey(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $ascii = strtoupper(Str::ascii(trim((string) $value)));
        $key = preg_replace('/[\s\-._\/:;]+/u', '', $ascii);

        return $key === '' ? null : $key;
    }

    private function machineIndex(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $this->index = [];
        foreach (Machine::query()->select(['id', 'asset_code'])->orderBy('id')->get() as $machine) {
            $key = self::canonicalKey($machine->asset_code);
            if ($key !== null) {
                $this->index[$key][] = $machine;
            }
        }

        return $this->index;
    }

    private function observed(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $observed = strtoupper(trim(preg_replace('/\s+/u', ' ', (string) $value)));

        return $observed === '' ? null : $observed;
    }

    private function result(string $status, ?string $observed, ?string $key, $matches): array
    {
        return [
            'status' => $status,
            'observed' => $observed,
            'normalized_key' => $key,
            'machine' => $status === 'MATCHED' ? $matches->first() : null,
            'candidate_machine_ids' => $matches->pluck('id')->all(),
            'candidate_asset_codes' => $matches->pluck('asset_code')->all(),
        ];
    }
}
