<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\CompanyCatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function __construct(private readonly CompanyCatalogService $catalog) {}

    public function index(): View
    {
        return view('companies.index', ['companies' => Company::orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'regex:/^[A-Z0-9][A-Z0-9_-]{0,19}$/', 'unique:companies,code'], 'name' => ['required', 'string', 'max:255']]);
        $this->catalog->save(null, $data + ['is_active' => true]);
        return back()->with('success', 'Đã thêm công ty.');
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'is_active' => ['nullable', 'boolean']]);
        $data['is_active'] = $request->boolean('is_active');
        $this->catalog->save($company, $data);
        return back()->with('success', 'Đã lưu công ty; mã liên kết được giữ nguyên.');
    }

    public function destroy(Company $company): RedirectResponse
    {
        $this->catalog->delete($company);
        return back()->with('success', 'Đã xóa công ty chưa được sử dụng.');
    }
}
