<?php

namespace App\Http\Controllers;

use App\Models\DossierSenderProfile;
use App\Models\Driver;
use App\Models\Machine;
use App\Models\MachineDriverHistory;
use App\Models\ZaloMessage;
use App\Models\ZaloSenderDriverLink;
use App\Models\ZaloSenderMachineMapping;
use App\Services\Dossiers\DossierAudit;
use App\Services\Dossiers\DossierRemoteJobs;
use App\Services\Dossiers\DossierService;
use App\Services\Dossiers\DossierStatus;
use App\Services\ZaloSenderMachineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DossierSenderMappingController extends Controller
{
    public function __invoke(Request $request, Driver $driver, DossierService $dossiers, ZaloSenderMachineService $mapping)
    {
        $data = $request->validate([
            'sender_id' => 'required|string|max:100|exists:zalo_messages,sender_id',
            'machine_id' => 'required|integer|exists:machines,id',
            'replace_history_id' => 'nullable|integer|exists:machine_driver_histories,id',
            'receipt' => 'nullable|string|size:64',
        ]);

        return DB::transaction(function () use ($request, $driver, $dossiers, $mapping, $data) {
            $actor = $request->user()->id;
            $machine = Machine::lockForUpdate()->findOrFail($data['machine_id']);
            $sender = ZaloMessage::where('sender_id', $data['sender_id'])->oldest('received_at')->oldest('id')->lockForUpdate()->firstOrFail();
            $senderName = ZaloMessage::where('sender_id', $sender->sender_id)->whereNotNull('sender_name')->latest('id')->value('sender_name');
            $at = app(DossierStatus::class)->now()->format('Y-m-d H:i:s');
            $current = ZaloSenderMachineMapping::where('sender_id', $sender->sender_id)->whereNull('valid_to')->lockForUpdate()->first();
            $links = ZaloSenderDriverLink::where('sender_id', $sender->sender_id)->whereNull('valid_to')->lockForUpdate()->get();
            $dossiers->check($links->count() <= 1, 'Sender có nhiều liên kết mở. Xử lý xung đột trước.');
            $link = $links->first();
            $profile = DossierSenderProfile::where('sender_id', $sender->sender_id)->lockForUpdate()->first();
            $dossiers->check(! $current || $current->valid_from->lte(now()), 'Mapping máy có hiệu lực trong tương lai.');
            $histories = MachineDriverHistory::where('machine_id', $machine->id)->where('started_at', '<=', $at)
                ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $at))->lockForUpdate()->get();
            $selected = empty($data['replace_history_id']) ? null : $histories->firstWhere('id', (int) $data['replace_history_id']);
            $dossiers->check(empty($data['replace_history_id']) || ($selected && $selected->driver_id !== $driver->id && ! $selected->ended_at),
                'Chọn đúng phân công đang mở của người cần thay trên máy này.');
            $dossiers->check(! $link || $link->driver_id === $driver->id || $link->driver_id === $selected?->driver_id,
                'Sender thuộc tài xế khác. Chọn đúng phân công cần thay để xác nhận.');
            $dossiers->check(! $profile || $profile->driver_id === $driver->id || $profile->driver_id === $selected?->driver_id,
                'Sender có hồ sơ liên kết với người khác. Chọn đúng phân công cần thay.');
            $dossiers->check(! $link || $link->valid_from->format('Y-m-d H:i:s') <= $at, 'Liên kết sender có hiệu lực trong tương lai.');
            $receipt = DossierRemoteJobs::digest([$actor, $driver->id, $sender->sender_id, $machine->id, $selected?->toArray(), $current?->toArray(), $link?->toArray(), $profile?->toArray(), $histories->modelKeys()]);
            if (empty($data['receipt'])) {
                return view('dossiers.sender-preview', compact('driver', 'sender', 'senderName', 'machine', 'current', 'link', 'selected', 'receipt'));
            }
            $dossiers->check(hash_equals($receipt, $data['receipt']), 'Mapping hoặc phân công vừa đổi. Xem lại trước khi xác nhận.');
            if ($selected) {
                $dossiers->check($selected->started_at < $at, 'Phân công vừa tạo. Thử lại sau một giây.');
                $before = $selected->toArray();
                $selected->update(['ended_at' => $at]);
                DossierAudit::write('driver_assignment_closed', $selected, $actor, $before);
            }
            if (! $histories->contains('driver_id', $driver->id)) {
                $dossiers->assign($machine, $driver, $at, null, $actor);
            }
            if (! $link || $link->driver_id !== $driver->id) {
                if ($link) {
                    $before = $link->toArray();
                    $link->update(['valid_to' => $at]);
                    DossierAudit::write('sender_driver_closed', $link, $actor, $before);
                }
                $newLink = ZaloSenderDriverLink::create(['sender_id' => $sender->sender_id, 'driver_id' => $driver->id,
                    'valid_from' => $at, 'created_by' => $actor]);
                DossierAudit::write('sender_driver_mapped', $newLink, $actor);
            }
            if ($profile && $profile->driver_id !== $driver->id) {
                $before = $profile->toArray();
                $profile->update(['driver_id' => $driver->id, 'user_id' => $actor]);
                DossierAudit::write('sender_profile_reassigned', $profile, $actor, $before);
            }
            $mapping->save($sender->sender_id, $machine->id, $actor, effectiveNow: true);

            return redirect()->route('dossiers.driver', $driver)->with('success', 'Đã lưu mapping Zalo và phân công được chọn.');
        }, 3);
    }
}
