<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationHealth;
use App\Models\PbxConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Response;

class AmiHealthController extends Controller
{
    /**
     * Display AMI health status.
     */
    public function index(): Response
    {
        $this->authorize('view pbx');

        $connection = PbxConnection::query()
            ->where('name', config('services.exp_pbx.connection_name'))
            ->first();

        $health = IntegrationHealth::query()
            ->where('component', 'ami_listener')
            ->first();

        return inertia('Admin/AmiHealth/Index', [
            'connection' => $connection,
            'health' => $health,
            'imports' => IntegrationHealth::whereIn('component', ['import_cdr', 'import_cel', 'call_correlation'])->get(),
        ]);
    }

    /**
     * Run an AMI health check.
     */
    public function check(): RedirectResponse
    {
        $this->authorize('view pbx');

        $connection = PbxConnection::query()
            ->where('name', config('services.exp_pbx.connection_name'))
            ->first();

        $isHealthy = $connection !== null
            && $connection->status === 'connected'
            && $connection->last_heartbeat_at !== null
            && $connection->last_heartbeat_at->gt(now()->subSeconds(config('services.exp_pbx.heartbeat_stale_seconds', 90)));

        $status = $isHealthy ? 'healthy' : 'unhealthy';
        $error = $isHealthy ? null : 'AMI listener is not connected or heartbeat is stale.';

        IntegrationHealth::record('ami_listener', $status, $error, [
            'connection_status' => $connection->status ?? 'disconnected',
            'last_event_at' => $connection?->last_event_at?->toIso8601String(),
            'last_heartbeat_at' => $connection?->last_heartbeat_at?->toIso8601String(),
        ]);

        if (! $isHealthy) {
            Log::warning('AMI health check failed.', ['connection' => $connection?->toArray()]);
        }

        return redirect()->route('admin.ami-health.index')
            ->with('success', 'AMI health check completed.');
    }
}
