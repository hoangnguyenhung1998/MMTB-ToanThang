@props(['selected' => null, 'includeInactive' => false])
@foreach(\App\Models\Company::query()->when(!$includeInactive, fn ($q) => $q->where(fn ($q) => $q->where('is_active', true)->orWhere('code', $selected)))->orderBy('name')->get() as $catalogCompany)
    <option value="{{ $catalogCompany->code }}" @selected($selected === $catalogCompany->code)>{{ $catalogCompany->name }}{{ $catalogCompany->is_active ? '' : ' (Ngừng sử dụng)' }}</option>
@endforeach
