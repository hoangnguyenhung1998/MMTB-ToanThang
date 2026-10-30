<?php

namespace App\Jobs;

use App\Models\DossierExport;
use App\Services\Dossiers\DossierPdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BuildDossierExport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public string $exportId)
    {
        $this->onConnection('dossiers')->onQueue(config('dossiers.queue'));
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(DossierPdf $pdf): void
    {
        $temp = 'exports/tmp-'.Str::uuid().'.pdf';
        try {
            DB::transaction(function () use ($pdf, $temp) {
                $e = DossierExport::lockForUpdate()->findOrFail($this->exportId);
                if ($e->status === 'ready') {
                    return;
                }
                $pdf->merge($e->manifest, $temp);
                $path = 'exports/'.$e->id.'.pdf';
                Storage::disk('dossiers')->move($temp, $path);
                $e->update(['path' => $path, 'status' => 'ready', 'sha256' => hash_file('sha256', Storage::disk('dossiers')->path($path)), 'error' => null]);
            }, 3);
        } finally {
            Storage::disk('dossiers')->delete($temp);
        }
    }

    public function failed(?\Throwable $e): void
    {
        DossierExport::whereKey($this->exportId)->where('status', '!=', 'ready')->update(['status' => 'failed',
            'error' => 'Không gộp được PDF. Kiểm tra PDF nguồn/checksum, giới hạn RAM và retry bộ này.']);
    }
}
