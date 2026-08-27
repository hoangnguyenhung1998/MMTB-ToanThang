<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteMachineIntakeOcrRequest;
use App\Http\Requests\FailOcrJobRequest;
use App\Models\MachineIntakeOcrJob;
use App\Services\MachineIntakeOcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
class MachineIntakeOcrController extends Controller
{
    public function __construct(private readonly MachineIntakeOcrService $service) {}
    public function claim(Request $request): JsonResponse
    {
        $data=$request->validate(['worker_id'=>['required','string','max:100']]); $job=$this->service->claim($data['worker_id']);
        if(!$job) return response()->json(null,204);
        return response()->json(['job'=>['id'=>$job->id,'attempts'=>$job->attempts,'document_type'=>$job->document->document_type,'image_url'=>route('api.ocr.intake.image',['machineIntakeOcrJob'=>$job,'worker_id'=>$data['worker_id']],false),'case'=>['id'=>$job->document->intakeCase->id,'reference'=>$job->document->intakeCase->reference]]]);
    }
    public function image(Request $request, MachineIntakeOcrJob $machineIntakeOcrJob): StreamedResponse
    {
        $data=$request->validate(['worker_id'=>['required','string','max:100']]); $this->service->ensureOwner($machineIntakeOcrJob,$data['worker_id']); $document=$machineIntakeOcrJob->document;
        abort_unless(Storage::disk($document->storage_disk)->exists($document->storage_path),404);
        return Storage::disk($document->storage_disk)->download($document->storage_path,$document->original_name,['Content-Type'=>$document->mime_type]);
    }
    public function complete(CompleteMachineIntakeOcrRequest $request, MachineIntakeOcrJob $machineIntakeOcrJob): JsonResponse { return response()->json(['job'=>$this->service->complete($machineIntakeOcrJob,$request->validated())]); }
    public function fail(FailOcrJobRequest $request, MachineIntakeOcrJob $machineIntakeOcrJob): JsonResponse { return response()->json(['job'=>$this->service->fail($machineIntakeOcrJob,$request->validated())]); }
}
