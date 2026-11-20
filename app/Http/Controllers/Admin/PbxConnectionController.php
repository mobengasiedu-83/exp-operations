<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PbxConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

class PbxConnectionController extends Controller
{
    /**
     * Display a listing of PBX connections.
     */
    public function index(): Response
    {
        $this->authorize('view pbx');

        $connections = PbxConnection::orderBy('name')->paginate(25);

        return inertia('Admin/PbxConnections/Index', [
            'connections' => $connections,
        ]);
    }

    /**
     * Store a newly created PBX connection.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('edit settings');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('pbx_connections')],
            'host' => ['required', 'string', 'max:255'],
            'ami_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
        ]);

        PbxConnection::create($validated);

        return redirect()->route('admin.pbx-connections.index')
            ->with('success', 'PBX connection created successfully.');
    }

    /**
     * Update the specified PBX connection.
     */
    public function update(Request $request, PbxConnection $pbxConnection): RedirectResponse
    {
        $this->authorize('edit settings');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('pbx_connections')->ignore($pbxConnection->id)],
            'host' => ['sometimes', 'string', 'max:255'],
            'ami_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
        ]);

        $pbxConnection->update($validated);

        return redirect()->route('admin.pbx-connections.index')
            ->with('success', 'PBX connection updated successfully.');
    }

    /**
     * Remove the specified PBX connection.
     */
    public function destroy(PbxConnection $pbxConnection): RedirectResponse
    {
        $this->authorize('edit settings');

        if ($pbxConnection->interactions()->exists() || $pbxConnection->telemetryGaps()->exists()) {
            throw ValidationException::withMessages(['connection' => 'This connection has call or telemetry history. Update its configuration instead of deleting it.']);
        }
        $pbxConnection->delete();

        return redirect()->route('admin.pbx-connections.index')
            ->with('success', 'PBX connection deleted successfully.');
    }
}
