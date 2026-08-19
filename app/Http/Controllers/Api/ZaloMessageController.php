<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreZaloMessageRequest;
use App\Services\ZaloIngestionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ZaloMessageController extends Controller
{
    public function __construct(private readonly ZaloIngestionService $ingestionService)
    {
    }

    public function store(StoreZaloMessageRequest $request): JsonResponse
    {
        $result = $this->ingestionService->ingest(
            $request->validated(),
            $request->file('file'),
        );

        return response()->json([
            'data' => [
                'message_id' => $result->attachment->message->message_id,
                'attachment_id' => $result->attachment->id,
                'status' => $result->attachment->status,
                'sha256' => $result->attachment->sha256,
                'duplicate_of_attachment_id' => $result->attachment->duplicate_of_attachment_id,
            ],
        ], $result->created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
