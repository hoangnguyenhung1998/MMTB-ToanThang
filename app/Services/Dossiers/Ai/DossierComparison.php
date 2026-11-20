<?php

namespace App\Services\Dossiers\Ai;

use App\Models\DossierRecord;
use App\Models\DossierSource;
use App\Models\Driver;
use App\Services\Dossiers\DossierService;
use App\Services\Dossiers\DossierStatus;

class DossierComparison
{
    public function identity(int $driver): array
    {
        $records = DossierRecord::with('versions')->where('driver_id', $driver)
            ->whereHas('type', fn ($q) => $q->where('ai_key', 'identity'))->get();
        $identities = $records->map(function ($record) {
            $version = app(DossierStatus::class)->current($record);

            return $version && ! $version->archived ? ($version->provenance['verified_identity'] ?? []) : [];
        })->filter()->unique()->values();

        $identity = $identities->count() === 1 ? $identities[0] : [];
        if ($identity && $this->otherDriverHasNumber($driver, $identity['identity'])) {
            return [];
        }

        return $identity;
    }

    private function otherDriverHasNumber(int $driver, string $number): bool
    {
        return Driver::whereKeyNot($driver)->where('cccd_no', $number)->exists() || DossierRecord::whereNotNull('driver_id')->where('driver_id', '!=', $driver)->whereHas('versions',
            fn ($query) => $query->where('confirmed', true)->where('provenance->verified_identity->identity', $number))->exists();
    }

    public function suggestedDriver(array $data, DossierSource $source, \Illuminate\Support\Collection $drivers): ?int
    {
        if ($data['subject'] !== 'driver') {
            return null;
        }
        $matches = $drivers->filter(fn ($driver) => $this->compare($data, $source, 'driver', $driver->id)['status'] === 'match');

        return $matches->count() === 1 ? $matches->first()->id : null;
    }

    public function compare(array $data, DossierSource $source, ?string $subject = null, ?int $id = null): array
    {
        $subject ??= $data['subject'];
        $fields = $data['fields'];
        if ($subject === 'driver') {
            $expected = $id ? $this->identity($id) : [];
            if ($id && ! $expected && ($number = Driver::whereKey($id)->value('cccd_no')) && ! $this->otherDriverHasNumber($id, $number)) {
                $expected = ['identity' => $number];
            }
            $keys = ['person_name', 'birth_date', 'identity'];
        } else {
            $machine = $source->machine;
            $expected = ['asset_code' => $machine?->asset_code, 'chassis_no' => $machine?->chassis_no, 'plate' => $machine?->plate_no];
            $keys = $machine?->dossier_group === 'vehicle' ? ['asset_code', 'chassis_no', 'plate'] : ['asset_code', 'chassis_no'];
        }

        $rows = [];
        foreach ($keys as $key) {
            $ocr = $fields[$key]['value'] ?? null;
            $web = $expected[$key] ?? null;
            $state = ! $ocr || ! $web ? 'missing' : ($this->normalize($key, $ocr) === $this->normalize($key, $web) ? 'match' : 'mismatch');
            $rows[$key] = ['ocr' => $ocr, 'web' => $web, 'state' => $state];
        }
        $matches = array_keys(array_filter($rows, fn ($row) => $row['state'] === 'match'));
        $mismatch = in_array('mismatch', array_column($rows, 'state'), true);
        $enough = $subject === 'driver'
            ? in_array('identity', $matches, true) || (count(array_intersect($matches, ['person_name', 'birth_date'])) === 2 && $this->uniqueNameBirth($id, $expected))
            : (bool) $matches;
        if ($data['type_key'] === 'bic_personal') {
            $enough = $subject === 'driver' && (bool) $this->insuranceEvidence($data, $expected);
        }

        return ['fields' => $rows, 'status' => $mismatch ? 'mismatch' : ($enough ? 'match' : 'missing')];
    }

    private function uniqueNameBirth(int $driver, array $identity): bool
    {
        $sameBirth = DossierRecord::whereNotNull('driver_id')->where('driver_id', '!=', $driver)
            ->whereHas('versions', fn ($query) => $query->where('confirmed', true)
                ->where('provenance->verified_identity->birth_date', $identity['birth_date']))
            ->with('versions')->get();
        foreach ($sameBirth as $record) {
            $version = app(DossierStatus::class)->current($record);
            $same = $version?->provenance['verified_identity'] ?? null;
            if ($same && ! $version->archived && $same['birth_date'] === $identity['birth_date'] &&
                $this->normalize('person_name', $same['person_name']) === $this->normalize('person_name', $identity['person_name'])) {
                return false;
            }
        }

        return true;
    }

    public function insuranceEvidence(array $data, array $identity): ?array
    {
        foreach ($data['insured'] as $person) {
            if (! in_array((int) $person['page'], $data['pages'], true) || empty($identity['identity']) || empty($person['identity'])
                || empty($identity['person_name']) || $this->normalize('person_name', $person['name']) !== $this->normalize('person_name', $identity['person_name'])) {
                continue;
            }
            $number = $this->normalize('identity', $person['identity']);
            if ($number !== '' && $number === $identity['identity'] && preg_match('/(?<![0-9])'.preg_quote($number, '/').'(?![0-9])/', $person['quote'])
                && str_contains($this->normalize('person_name', $person['quote']), $this->normalize('person_name', $identity['person_name']))) {
                return ['page' => (int) $person['page'], 'quote' => $person['quote']];
            }
        }

        return null;
    }

    protected function normalize(string $field, string $value): string
    {
        return $field === 'person_name' ? mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)))
            : mb_strtoupper(preg_replace('/[^\pL\pN]/u', '', $value));
    }

    public function verifyIdentity(array $input): array
    {
        $values = validator($input, ['person_name' => 'required|string|max:255', 'birth_date' => 'nullable|date_format:Y-m-d', 'identity' => 'required|regex:/^\d{9}(\d{3})?$/D'])->validate();
        $driver = Driver::lockForUpdate()->findOrFail($input['subject_id']);
        app(DossierService::class)->check(! $this->otherDriverHasNumber($driver->id, $values['identity']),
            'Số CCCD đã được đối chiếu trong hồ sơ của người khác.');
        if (empty($values['birth_date'])) {
            $linked = app(\App\Services\DossierZaloRoster::class)->identities()
                ->contains(fn ($ids) => $ids->count() === 1 && $ids->contains($driver->id));
            app(DossierService::class)->check($linked, 'Cần xác nhận ID Zalo của tài xế trong cấu hình check-in trước khi lưu CCCD.');
        }
        $existing = $this->identity($driver->id);
        app(DossierService::class)->check((! $driver->cccd_no || $driver->cccd_no === $values['identity']) && (! $existing || ($existing['identity'] === $values['identity'] && $this->normalize('person_name', $existing['person_name']) === $this->normalize('person_name', $values['person_name']) && (empty($existing['birth_date']) || empty($values['birth_date']) || $existing['birth_date'] === $values['birth_date']))), 'CCCD đã đối chiếu khác hồ sơ hiện có. Kiểm tra riêng trước khi thay.');

        return [...$existing, ...array_filter($values, fn ($value) => $value !== null)];
    }
}
