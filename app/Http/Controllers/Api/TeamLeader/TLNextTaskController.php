<?php

namespace App\Http\Controllers\Api\TeamLeader;

use App\Exceptions\QueueOfferException;
use App\Http\Controllers\Controller;
use App\Services\NextTaskQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Team Leader's view of a queued next task. Accepting only reserves it; the dispatcher
 * assigns it once the current job is released.
 */
class TLNextTaskController extends Controller
{
    public function __construct(protected NextTaskQueueService $queue)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->queue->presentForTeamLeader($request->user())]);
    }

    public function accept(Request $request, int $offer): JsonResponse
    {
        return $this->answer($request, $offer, 'accept');
    }

    public function decline(Request $request, int $offer): JsonResponse
    {
        return $this->answer($request, $offer, 'decline');
    }

    protected function answer(Request $request, int $offerId, string $decision): JsonResponse
    {
        try {
            $this->queue->respond($request->user(), $offerId, $decision === 'accept' ? 'accept' : 'reject');
        } catch (QueueOfferException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'reason' => $e->reason], $e->status);
        }

        return response()->json([
            'success' => true,
            'message' => $decision === 'accept'
                ? 'Reserved. Dispatch will assign it to you once you finish your current job.'
                : 'Declined.',
            'data' => $decision === 'accept' ? $this->queue->presentForTeamLeader($request->user()) : null,
        ]);
    }
}
