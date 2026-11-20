<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCallControlCommandRequest;
use App\Models\CallerCase;
use App\Models\User;
use App\Services\CallControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CallControlController extends Controller
{
    public function show(Request $request, CallerCase $case, CallControl $control): JsonResponse
    {
        $this->authorize('view cases');
        $this->authorize('control calls');
        $this->owned($case, $request->user());

        return response()->json($control->status($case));
    }

    public function store(StoreCallControlCommandRequest $request, CallerCase $case, CallControl $control): JsonResponse
    {
        $this->owned($case, $request->user());
        $command = $control->execute($case, $request->user(), $request->validated());

        return response()->json(['command' => $command->only(['request_key', 'action', 'status', 'error_code']), 'message' => 'Request recorded. Wait for verified PBX state before another action.']);
    }

    private function owned(CallerCase $case, User $user): void
    {
        abort_unless($case->assigned_to === $user->id || $user->hasRole('Super Administrator'), 404);
    }
}
