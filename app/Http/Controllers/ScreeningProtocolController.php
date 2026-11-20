<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreScreeningProtocolRequest;
use App\Models\ScreeningProtocol;
use App\Models\ScreeningQuestion;
use App\Services\CaseWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ScreeningProtocolController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('manage screening protocols'), 403);

        return Inertia::render('Workspace/Protocols', [
            'protocols' => ScreeningProtocol::query()->with(['questions' => fn ($query) => $query->with('conditionalQuestion:id,prompt,position')])->latest('id')->paginate(10)->withQueryString(),
            'canManage' => true,
            'dispositions' => CaseWorkflow::DISPOSITIONS,
            'questionTypes' => ScreeningQuestion::typeLabels(),
            'safetyRules' => ScreeningQuestion::SAFETY_RULES,
        ]);
    }

    public function store(StoreScreeningProtocolRequest $request): RedirectResponse
    {
        $this->authorize('manage screening protocols');
        $data = $request->validated();
        $protocol = DB::transaction(function () use ($request, $data): ScreeningProtocol {
            $protocol = ScreeningProtocol::query()->create([
                ...$request->safe()->except('questions'), 'status' => 'active', 'is_active' => false, 'created_by' => $request->user()?->id,
            ]);

            $questionIds = [];

            foreach ($data['questions'] as $position => $question) {
                $questionIds[$position] = $protocol->questions()->create([
                    'prompt' => $question['prompt'],
                    'type' => $question['type'] ?? 'yes_no_unknown',
                    'options' => $question['options'] ?? null,
                    'required' => (bool) ($question['required'] ?? true),
                    'help_text' => $question['help_text'] ?? null,
                    'safety_rule' => $question['safety_rule'] ?? null,
                    'positive_priority' => $question['positive_priority'],
                    'position' => $position,
                ])->id;
            }

            foreach ($data['questions'] as $position => $question) {
                if (($question['conditional_on_index'] ?? null) === null) {
                    continue;
                }

                ScreeningQuestion::query()->whereKey($questionIds[$position])->update([
                    'conditional_question_id' => $questionIds[(int) $question['conditional_on_index']] ?? null,
                    'conditional_answer' => $question['conditional_answer'] ?? null,
                ]);
            }

            $protocol->activate();

            activity('screening-protocol')->causedBy($request->user())->performedOn($protocol)
                ->withProperties(['version' => $protocol->version, 'questions' => count($data['questions'])])
                ->log('Questionnaire version activated');

            return $protocol;
        });

        return back()->with('success', 'Questionnaire version '.$protocol->version.' saved and activated. New calls use it immediately.');
    }

    public function activate(Request $request, ScreeningProtocol $protocol): RedirectResponse
    {
        $this->authorize('manage screening protocols');
        DB::transaction(function () use ($request, $protocol): void {
            $protocol = ScreeningProtocol::query()->lockForUpdate()->findOrFail($protocol->id);
            abort_if($protocol->status === 'retired', 422, 'A retired version cannot be reactivated. Copy it into a new version instead.');
            $protocol->activate();
            activity('screening-protocol')->causedBy($request->user())->performedOn($protocol)->log('Questionnaire version activated');
        });

        return back()->with('success', 'Questionnaire version '.$protocol->version.' is now active for new calls.');
    }

    public function retire(ScreeningProtocol $protocol): RedirectResponse
    {
        $this->authorize('manage screening protocols');
        DB::transaction(function () use ($protocol): void {
            $protocol = ScreeningProtocol::query()->lockForUpdate()->findOrFail($protocol->id);
            abort_if($protocol->isActive(), 422, 'Activate a replacement version before retiring the current questionnaire.');
            $protocol->update(['status' => 'retired', 'is_active' => false]);
            activity('screening-protocol')->causedBy(auth()->user())->performedOn($protocol)->log('Questionnaire version retired');
        });

        return back()->with('success', 'Version retired. Historical records are preserved.');
    }
}
