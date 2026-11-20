<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    /**
     * Display a listing of permissions.
     */
    public function index(): Response
    {
        $this->authorize('view permissions');

        $permissions = Permission::orderBy('name')->paginate(25);

        return inertia('Admin/Permissions/Index', [
            'permissions' => $permissions,
        ]);
    }
}
