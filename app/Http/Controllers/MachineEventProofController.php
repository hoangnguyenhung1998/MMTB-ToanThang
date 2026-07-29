<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\MachineEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class MachineEventProofController extends Controller
{
    public function edit(Machine $machine, MachineEvent $event): View
    {
        abort_unless($event->machine_id === $machine->id, 404);

        return view('machines.events.edit-proof', [
            'machine' => $machine,
            'event' => $event,
        ]);
    }

    public function update(Request $request, Machine $machine, MachineEvent $event): RedirectResponse
    {
        abort_unless($event->machine_id === $machine->id, 404);

        $validated = $request->validate([
            'proof_file' => ['nullable', 'file', 'max:204800'],
            'occurred_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
        ]);

        if ($request->hasFile('proof_file')) {
            if ($event->proof_file_path) {
                Storage::disk('public')->delete($event->proof_file_path);
            }

            $occurredAt = $validated['occurred_at'] ?? (string) $event->occurred_at;
            $filename = $this->buildProofFileName(
                $event,
                $machine,
                $request->file('proof_file')->getClientOriginalExtension(),
                $occurredAt
            );

            $path = $request->file('proof_file')
                ->storeAs("documents/machines/{$machine->asset_code}/proofs", $filename, 'public');

            $event->proof_file_path = $path;
            if ($event->type === 'HANDOVER') {
                $event->missing_proof = 0;
            }
        }

        if (!empty($validated['occurred_at'])) {
            $event->occurred_at = Carbon::parse($validated['occurred_at']);
        }

        if (array_key_exists('note', $validated)) {
            $event->note = $validated['note'];
        }

        $event->save();

        return redirect()
            ->route('machines.show', $machine)
            ->with('success', 'Đã cập nhật biên bản sự kiện.');
    }

    public function destroy(Machine $machine, MachineEvent $event): RedirectResponse
    {
        abort_unless($event->machine_id === $machine->id, 404);

        if ($event->proof_file_path) {
            Storage::disk('public')->delete($event->proof_file_path);
        }

        $event->proof_file_path = null;
        if ($event->type === 'HANDOVER') {
            $event->missing_proof = 1;
        }
        $event->save();

        return redirect()
            ->route('machines.show', $machine)
            ->with('success', 'Đã xóa file biên bản.');
    }

    private function buildProofFileName(MachineEvent $event, Machine $machine, string $extension, string $occurredAt): string
    {
        $ext = strtolower($extension ?: 'dat');
        $occurred = Carbon::parse($occurredAt);

        if ($event->type === 'HANDOVER') {
            return sprintf(
                '%s__BAN_GIAO__%s_%s.%s',
                $machine->asset_code,
                $occurred->format('Y-m-d'),
                $occurred->format('His'),
                $ext
            );
        }

        if ($event->type === 'TRANSFER') {
            $outAt = $occurred;
            $inAt = $occurred;

            return sprintf(
                '%s__DIEU_CHUYEN__OUT_%s_%s__IN_%s_%s.%s',
                $machine->asset_code,
                $outAt->format('Y-m-d'),
                $outAt->format('His'),
                $inAt->format('Y-m-d'),
                $inAt->format('His'),
                $ext
            );
        }

        return sprintf(
            '%s__TRA__%s_%s.%s',
            $machine->asset_code,
            $occurred->format('Y-m-d'),
            $occurred->format('His'),
            $ext
        );
    }
}
