<?php

namespace App\Services\Dossiers;

use App\Models\DossierRecord;
use App\Models\DossierReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DossierReminderService
{
    public function run(): int
    {
        $recipients = config('dossiers.telegram_recipients', []);
        if (! config('dossiers.telegram_enabled') || ! config('telegram.bot_token') || ! $recipients
            || collect($recipients)->contains(fn ($r) => ! preg_match('/^(?:-?[0-9]+|@[A-Za-z0-9_]{5,})$/D', trim($r)))) {
            return 0;
        }
        $sent = 0;
        $status = app(DossierStatus::class);
        foreach (DossierRecord::with(['type', 'versions'])->cursor() as $record) {
            $version = $status->current($record);
            $state = $status->describe($version, $record->type->warning_days);
            if (! $version || ! in_array($state['status'], ['expiring', 'expired'], true)) {
                continue;
            }
            foreach ($record->type->warning_days as $milestone) {
                if ($state['days'] > $milestone) {
                    continue;
                }
                foreach (array_unique(array_map('trim', $recipients)) as $recipient) {
                    $delivery = DossierReminder::firstOrCreate(['version_id' => $version->id, 'milestone' => $milestone, 'recipient' => $recipient]);
                    $claimed = DB::transaction(function () use ($delivery) {
                        $d = DossierReminder::lockForUpdate()->findOrFail($delivery->id);
                        if (! in_array($d->status, ['pending', 'failed'], true) || $d->attempts >= 3) {
                            return false;
                        }
                        // Durable reservation BEFORE network; abandoned/uncertain sends never auto replay.
                        $d->update(['status' => 'sending', 'attempts' => $d->attempts + 1, 'error' => null]);

                        return true;
                    });
                    if (! $claimed) {
                        continue;
                    }
                    try {
                        $response = Http::asJson()->timeout(10)->post('https://api.telegram.org/bot'.config('telegram.bot_token').'/sendMessage', [
                            'chat_id' => $recipient, 'text' => 'Hồ sơ #'.$record->id.' / phiên bản #'.$version->id.' — '.$record->type->name.' — '.$state['label'].' — còn '.$state['days'].' ngày (mốc '.$milestone.')',
                        ]);
                        if ($response->successful() && $response->json('ok') === true) {
                            DossierReminder::whereKey($delivery->id)->update(['status' => 'sent']);
                            $sent++;
                        } else {
                            DossierReminder::whereKey($delivery->id)->update(['status' => $response->serverError() ? 'uncertain' : 'failed', 'error' => 'Telegram chưa xác nhận gửi; tối đa 3 lần với lỗi từ chối rõ ràng, lỗi không rõ cần kiểm tra.']);
                        }
                    } catch (\Throwable) {
                        DossierReminder::whereKey($delivery->id)->update(['status' => 'uncertain', 'error' => 'Không rõ Telegram đã nhận hay chưa; kiểm tra trước khi cho gửi lại để tránh trùng.']);
                    }
                }
            }
        }

        return $sent;
    }
}
