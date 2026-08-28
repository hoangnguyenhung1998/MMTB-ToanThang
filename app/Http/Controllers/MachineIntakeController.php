<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Requests\AssignMachineIntakeCodeRequest;
use App\Http\Requests\ConfirmMachineIntakeRequest;
use App\Http\Requests\StoreMachineIntakeRequest;
use App\Models\MachineIntakeCase;
use App\Services\MachineIntakeService;
use App\Services\MachineIntakeOcrService;
use App\Models\MachineIntakeDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Services\MachineIntakeBchService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use App\Models\Project;
use App\Models\MachineIntakeEmailReply;
use App\Services\MachineIntakeEmailReplyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MachineIntakeController extends Controller
{
    public function __construct(private readonly MachineIntakeService $service, private readonly MachineIntakeOcrService $ocr, private readonly MachineIntakeBchService $bch, private readonly MachineIntakeEmailReplyService $emailReplies) {}

    public function index(Request $request): View
    {
        $status = $request->string('status')->toString();
        $q = $request->string('q')->toString();
        $query = MachineIntakeCase::query()->with('machine')
            ->when($status, fn ($builder) => $builder->where('status', $status))
            ->when($q, fn ($builder) => $builder->where(fn ($inner) => $inner
                ->where('reference', 'like', "%{$q}%")->orWhere('chassis_no', 'like', "%{$q}%")
                ->orWhere('engine_no', 'like', "%{$q}%")->orWhere('asset_code', 'like', "%{$q}%")));

        return view('machine-intakes.index', [
            'cases' => $query->latest()->paginate(20)->withQueryString(), 'status' => $status, 'q' => $q,
            'summary' => [
                'all' => MachineIntakeCase::count(),
                'waiting' => MachineIntakeCase::where('status', 'WAIT_ASSET_CODE')->count(),
                'handover' => MachineIntakeCase::where('status', 'WAIT_HANDOVER')->count(),
                'draft' => MachineIntakeCase::whereIn('status', ['NEW', 'EXTRACTED', 'CONFIRMED'])->count(),
            ],
        ]);
    }

    public function create(): View { return view('machine-intakes.create'); }

    public function store(StoreMachineIntakeRequest $request): RedirectResponse
    {
        $case = $this->service->createDraft($request->validated(), $request->file('documents', []), $request->user());
        return redirect()->route('machine-intakes.show', $case)->with('success', "Đã tạo hồ sơ {$case->reference}.");
    }

    public function show(MachineIntakeCase $machineIntake): View
    {
        return view('machine-intakes.show', ['case' => $machineIntake->load(['documents', 'events.user', 'machine','project','emailReplies']), 'projects'=>Project::orderBy('name')->get(['id','name'])]);
    }

    public function confirm(ConfirmMachineIntakeRequest $request, MachineIntakeCase $machineIntake): RedirectResponse
    {
        return $this->run(fn () => $this->service->confirm($machineIntake, $request->validated(), $request->user()), 'Đã xác nhận dữ liệu chính xác.');
    }

    public function markEmailSent(Request $request, MachineIntakeCase $machineIntake): RedirectResponse
    {
        $data = $request->validate(['email_thread_id' => ['nullable', 'string', 'max:255'], 'email_message_id' => ['nullable', 'string', 'max:255']]);
        return $this->run(fn () => $this->service->markEmailSent($machineIntake, $data, $request->user()), 'Đã chuyển hồ sơ sang chờ cấp mã.');
    }

    public function assignCode(AssignMachineIntakeCodeRequest $request, MachineIntakeCase $machineIntake): RedirectResponse
    {
        return $this->run(fn () => $this->service->assignAssetCode($machineIntake, $request->validated(), $request->user(), $request->file('evidence')), 'Đã cấp mã, tạo máy chờ bàn giao và gửi thông báo Telegram.');
    }

    public function confirmEmailCode(Request $request, MachineIntakeCase $machineIntake, MachineIntakeEmailReply $reply): RedirectResponse
    {
        abort_unless($reply->machine_intake_case_id === $machineIntake->id, 404);
        return $this->run(fn () => $this->emailReplies->confirm($reply, $request->user()), 'Đã xác nhận mã từ Gmail và tạo máy chờ bàn giao.');
    }

    public function requeue(Request $request, MachineIntakeCase $machineIntake): RedirectResponse
    {
        $count=$this->ocr->enqueueCase($machineIntake->load('documents'),true);
        return back()->with('success',"Đã đưa {$count} tài liệu vào hàng đợi OCR.");
    }

    public function document(Request $request, MachineIntakeCase $machineIntake, MachineIntakeDocument $document): StreamedResponse
    {
        abort_unless($document->machine_intake_case_id===$machineIntake->id,404);
        abort_unless(Storage::disk($document->storage_disk)->exists($document->storage_path),404);
        return Storage::disk($document->storage_disk)->response($document->storage_path,$document->original_name,['Content-Type'=>$document->mime_type,'Content-Disposition'=>'inline']);
    }

    public function prepareBch(Request $request, MachineIntakeCase $machineIntake): RedirectResponse
    {
        $data=$request->validate(['to'=>['required','string','max:1000'],'cc'=>['nullable','string','max:1000'],'subject'=>['required','string','max:255'],'body'=>['required','string','max:5000']]);$this->bch->prepare($machineIntake,$data);return back()->with('success','Đã tạo file Excel và bản xem trước email.');
    }
    public function sendBch(Request $request, MachineIntakeCase $machineIntake): RedirectResponse {return $this->run(fn()=>$this->bch->send($machineIntake,$request->user()),'Đã gửi hồ sơ BCH và chuyển sang chờ cấp mã.');}
    public function downloadBch(MachineIntakeCase $machineIntake): BinaryFileResponse {abort_unless($machineIntake->bch_package_path,404);return response()->download(storage_path('app/public/'.$machineIntake->bch_package_path));}

    private function run(callable $action, string $message): RedirectResponse
    {
        try { $action(); } catch (BusinessRuleException $exception) { return back()->withErrors(['error' => $exception->getMessage()])->withInput(); }
        return back()->with('success', $message);
    }
}
