<?php

namespace App\Http\Controllers;

use App\Models\CommandCenter;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CommandCenterController extends Controller
{
    public function projectIndex(Project $project): View
    {
        $commandCenters = CommandCenter::orderBy('name')->get();

        return view('projects.command-centers', [
            'project' => $project,
            'commandCenters' => $commandCenters,
        ]);
    }

    public function projectStore(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:command_centers,name'],
            'note' => ['nullable', 'string'],
        ]);

        CommandCenter::create($validated);

        return redirect()
            ->route('project-command-centers.index', $project)
            ->with('success', 'Tạo BCH thành công.');
    }

    public function index(): View
    {
        $commandCenters = CommandCenter::orderBy('name')->get();

        return view('command-centers.index', [
            'commandCenters' => $commandCenters,
        ]);
    }

    public function edit(int $id): View
    {
        $commandCenter = CommandCenter::findOrFail($id);

        return view('command-centers.edit', [
            'commandCenter' => $commandCenter,
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $commandCenter = CommandCenter::findOrFail($id);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:command_centers,name,' . $commandCenter->id],
            'note' => ['nullable', 'string'],
        ]);

        $commandCenter->update($validated);

        return redirect()
            ->route('command-centers.index')
            ->with('success', 'Cập nhật BCH thành công.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $commandCenter = CommandCenter::findOrFail($id);
        $commandCenter->delete();

        return redirect()
            ->route('command-centers.index')
            ->with('success', 'Đã xoá BCH.');
    }
}
