<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\GuideLibrary;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuideController extends Controller
{
    public function __construct(private readonly GuideLibrary $guides) {}

    /**
     * List the manuals the signed-in user is allowed to read.
     */
    public function index(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('Guides/Index', [
            'guides' => $this->guides->visibleTo($user),
        ]);
    }

    /**
     * Render one manual. The permission check happens server-side so a guide
     * cannot be read by guessing its URL.
     */
    public function show(Request $request, string $guide): Response
    {
        $user = $this->user($request);
        $meta = $this->guides->meta($guide);

        abort_unless($user->can($meta['permission']), 403);

        return Inertia::render('Guides/Show', [
            'guide' => $meta,
            'document' => $this->guides->document($guide),
            'others' => $this->guides->visibleTo($user, $guide),
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
