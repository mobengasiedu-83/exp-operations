<?php

namespace App\Services;

use App\Models\CallerCase;
use App\Models\ScreeningDraft;
use App\Models\ScreeningProtocol;
use App\Models\ScreeningQuestion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CaseWorkflow
{
    public const PRIORITIES = ['P1', 'P2', 'P3', 'S', 'Review'];

    public const DISPOSITIONS = [
        'emergency_escalation' => ['label' => 'Emergency instructions and supervisor escalation', 'priorities' => ['P1']],
        'urgent_referral' => ['label' => 'Urgent referral / priority clinician handover', 'priorities' => ['P2']],
        'routine_handover' => ['label' => 'Routine clinician handover', 'priorities' => ['P3']],
        'callback_requested' => ['label' => 'Callback request recorded', 'priorities' => ['P3']],
        'faq_resolved' => ['label' => 'Service FAQ resolved', 'priorities' => ['S']],
        'clinical_review' => ['label' => 'Clinical review required', 'priorities' => ['Review']],
        'contact_ended' => ['label' => 'Contact ended / no clinical assessment', 'priorities' => ['Review']],
    ];

    /** @param array<string, mixed> $data */
    public function change(CallerCase $case, User $user, array $data, string $operation): void
    {
        DB::transaction(function () use ($case, $user, $data, $operation): void {
            $case = CallerCase::query()->visibleTo($user)->lockForUpdate()->findOrFail($case->id);
            $withdrawal = $operation === 'consent' && ($data['status'] ?? null) === 'withdrawn';
            $this->require($case->status !== 'closed' || $withdrawal, 'case', 'This case is closed. Only withdrawal of existing consent may be recorded.');
            $this->require($case->revision === (int) $data['revision'], 'revision', 'This case changed. Reload it before saving to avoid overwriting another operator.');

            match ($operation) {
                'consent' => $this->consent($case, $user, $data),
                'profile' => $this->profile($case, $data),
                'screening' => $this->screen($case, $user, $data),
                'note' => $this->note($case, $user, $data),
                'close' => $this->close($case, $user, $data),
                default => throw new \InvalidArgumentException('Unknown case operation.'),
            };

            $case->increment('revision');
            $evidence = $operation === 'close' ? [
                'disposition' => $data['disposition'],
                'emergency_action_confirmed' => $data['emergency_action_confirmed'] ?? false,
                'supervisor_notified' => $data['supervisor_notified'] ?? false,
                'faq_confirmed' => $data['faq_confirmed'] ?? false,
                'screening_id' => $case->screenings()->latest('id')->value('id'),
            ] : [];
            activity('case-workflow')->causedBy($user)->performedOn($case)
                ->withProperties(['operation' => $operation, 'revision' => $case->revision, ...$evidence])
                ->log('Case workflow updated');
        });
    }

    public function protocol(int $id): ScreeningProtocol
    {
        return ScreeningProtocol::query()->with('questions')->lockForUpdate()->findOrFail($id);
    }

    /**
     * A new consent capture must use the version administrators currently
     * publish. Historical records keep working against their own version
     * through protocol().
     */
    public function activeProtocol(int $id): ScreeningProtocol
    {
        $protocol = $this->protocol($id);
        $this->require($protocol->isActive(), 'protocol_id', 'Select the active questionnaire version before recording answers.');

        return $protocol;
    }

    /**
     * Answers may be recorded against the version administrators currently
     * publish or a version already used by this case. Existing consent records
     * remain a legacy version reference, but consent is not required to capture
     * answers. Drafts keep a questionnaire usable when the active version changes.
     */
    private function captureProtocol(CallerCase $case, int $id): ScreeningProtocol
    {
        $protocol = $this->protocol($id);
        $consent = $case->consents()->latest('id')->first();
        $permitted = $protocol->isActive()
            || $case->screenings()->where('screening_protocol_id', $protocol->id)->exists()
            || $case->screeningDrafts()->where('screening_protocol_id', $protocol->id)->exists()
            || ($consent?->status === 'granted' && (int) $consent->screening_protocol_id === $protocol->id);
        $this->require($permitted, 'protocol_id', 'Select the active questionnaire version before recording answers.');

        return $protocol;
    }

    /** Keep an in-progress record on its questionnaire version without requiring consent. */
    public function recordProtocol(CallerCase $case): ?ScreeningProtocol
    {
        $consent = $case->consents()->latest('id')->first();
        $protocolId = $case->screeningDrafts()->latest('id')->value('screening_protocol_id')
            ?? $case->screenings()->latest('id')->value('screening_protocol_id')
            ?? ($consent?->status === 'granted' ? $consent->screening_protocol_id : null);
        $protocol = $protocolId === null ? null : ScreeningProtocol::query()->with('questions')->find($protocolId);
        $protocol ??= ScreeningProtocol::query()->with('questions')->active()->latest('id')->first();

        return $protocol;
    }

    /**
     * Autosave questionnaire answers without confirming them. Drafts are kept
     * outside screening history until the agent records the safety screen.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(CallerCase $case, User $user, array $data): ScreeningDraft
    {
        return DB::transaction(function () use ($case, $user, $data): ScreeningDraft {
            $case = CallerCase::query()->visibleTo($user)->lockForUpdate()->findOrFail($case->id);
            $this->require($case->status !== 'closed', 'case', 'This case is closed. Autosaved answers cannot be changed.');
            $protocol = $this->captureProtocol($case, (int) $data['protocol_id']);
            $this->require($protocol->questions->isNotEmpty(), 'answers', 'The questionnaire has no questions.');
            $answers = collect($data['answers'])->keyBy('question_id');
            $this->require($answers->keys()->diff($protocol->questions->modelKeys())->isEmpty(), 'answers', 'Answers must belong to the selected protocol version.');

            $draft = ScreeningDraft::query()
                ->where('case_id', $case->id)
                ->where('screening_protocol_id', $protocol->id)
                ->lockForUpdate()->first();

            if ($draft !== null && isset($data['revision']) && $draft->revision !== (int) $data['revision']) {
                throw new ConflictHttpException('These answers were saved in another window. Reload the case before continuing.');
            }

            $savedAt = now();
            $values = [
                'recorded_by' => $user->id,
                'priority' => $data['priority'],
                'answers' => $this->draftAnswers($protocol, $answers, $user, $savedAt),
                'draft_saved_at' => $savedAt,
            ];

            if ($draft === null) {
                $draft = ScreeningDraft::query()->create([
                    'case_id' => $case->id, 'screening_protocol_id' => $protocol->id, 'revision' => 1, ...$values,
                ]);
            } else {
                $draft->update([...$values, 'revision' => $draft->revision + 1]);
            }

            return $draft->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    private function consent(CallerCase $case, User $user, array $data): void
    {
        if ($data['status'] === 'withdrawn') {
            $previous = $case->consents()->latest('id')->first();
            $this->require($previous?->status === 'granted' && $previous->screening_protocol_id === (int) $data['protocol_id'], 'consent', 'Withdraw the currently granted consent using its original notice version.');
            $protocolId = $previous->screening_protocol_id;
        } else {
            $protocolId = $this->activeProtocol((int) $data['protocol_id'])->id;
        }
        $this->require($data['status'] === 'granted' || ! ($data['callback_allowed'] ?? false), 'callback_allowed', 'Callback permission requires consent to proceed.');
        $case->consents()->create([
            'screening_protocol_id' => $protocolId, 'recorded_by' => $user->id,
            'status' => $data['status'], 'callback_allowed' => $data['callback_allowed'] ?? false, 'recorded_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function profile(CallerCase $case, array $data): void
    {
        $protocol = $this->recordProtocol($case);
        $child = $data['age_band'] === 'child';
        $this->require(! $child || ($protocol?->allow_guardian ?? false), 'age_band', 'Child and guardian data capture is not enabled in this questionnaire version. Escalate to the supervisor.');
        $this->require(! $child || (filled($data['guardian_name'] ?? null) && filled($data['guardian_relationship'] ?? null) && ($data['guardian_confirmed'] ?? false)), 'guardian_name', 'Record the guardian relationship and confirmation for a child.');
        $number = filled($data['callback_number'] ?? null) ? GhanaPhoneNumber::normalize($data['callback_number']) : null;
        $this->require(! filled($data['callback_number'] ?? null) || $number !== null, 'callback_number', 'Enter a valid Ghana mobile callback number.');
        $case->update([
            'preferred_name' => $data['preferred_name'] ?? null, 'callback_number' => $number,
            'age_band' => $data['age_band'], 'guardian_name' => $child ? $data['guardian_name'] : null,
            'guardian_relationship' => $child ? $data['guardian_relationship'] : null,
            'guardian_confirmed' => $child && (bool) ($data['guardian_confirmed'] ?? false),
            'contact_kind' => $data['contact_kind'], 'concern_type' => $data['concern_type'],
            'concern' => $data['concern'] ?? null, 'onset' => $data['onset'] ?? null, 'impact' => $data['impact'] ?? null,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function screen(CallerCase $case, User $user, array $data): void
    {
        $protocol = $this->captureProtocol($case, (int) $data['protocol_id']);
        if (! is_array($data['answers'])) {
            throw ValidationException::withMessages(['answers' => 'Provide the questionnaire answers.']);
        }
        $answers = collect($data['answers'])->keyBy('question_id');
        $questionIds = $protocol->questions->modelKeys();
        $this->require($protocol->questions->isNotEmpty(), 'answers', 'The questionnaire has no questions.');
        $this->require($answers->keys()->diff($questionIds)->isEmpty(), 'answers', 'Answers must belong to the selected protocol version.');

        $asked = $protocol->questions->filter(fn (ScreeningQuestion $question): bool => $this->isAsked($question, $answers));
        $notAsked = $answers->keys()->diff($asked->modelKeys());
        $stray = $notAsked->isEmpty() ? null : $protocol->questions->firstWhere('id', (int) $notAsked->first())?->prompt;
        $this->require($notAsked->isEmpty(), 'answers', 'Answer the earlier question before recording "'.$stray.'".');

        foreach ($asked as $question) {
            $answer = trim((string) ($answers->get($question->id)['answer'] ?? ''));
            $this->require($this->isValidAnswer($question, $answer), 'answers', 'Record a valid answer for "'.$question->prompt.'".');
        }

        $missing = $asked->filter(fn (ScreeningQuestion $question): bool => $question->required && ! filled($answers->get($question->id)['answer'] ?? null));
        $positive = $asked->filter(fn (ScreeningQuestion $question): bool => $this->isPositiveAnswer((string) ($answers->get($question->id)['answer'] ?? '')));
        $emergency = $positive->contains('positive_priority', 'P1');
        $complete = $missing->isEmpty();
        $unknown = $asked->contains(fn (ScreeningQuestion $question): bool => strtolower(trim((string) ($answers->get($question->id)['answer'] ?? ''))) === 'unknown');
        $this->require($emergency || $complete, 'answers', 'Answer every required safety question, or record unknown. Emergency escalation may interrupt the questionnaire.');
        $priority = match (true) {
            $emergency => 'P1',
            $positive->contains('positive_priority', 'P2') => 'P2',
            $unknown || ! $complete => 'Review',
            default => $data['priority'],
        };
        $rank = ['P1' => 5, 'P2' => 4, 'Review' => 3, 'P3' => 2, 'S' => 1];
        if ($rank[$data['priority']] > $rank[$priority]) {
            $priority = $data['priority'];
        }
        if ($case->priority !== null && $rank[$case->priority] > $rank[$priority]) {
            $this->require($user->can('review cases') && filled($data['review_reason'] ?? null) && $complete && ! $unknown && $positive->isEmpty(), 'priority', 'Earlier safety findings require a complete reassessment and a clinical reviewer reason before lowering priority.');
            $case->notes()->create(['recorded_by' => $user->id, 'body' => 'Clinical reassessment: '.$data['review_reason']]);
        }
        $this->require($priority !== 'S' || $case->concern_type === 'service_information', 'priority', 'Personal clinical concerns cannot be resolved as a service FAQ.');
        $savedAt = now();
        $screening = $case->screenings()->create([
            'screening_protocol_id' => $protocol->id, 'recorded_by' => $user->id,
            'priority' => $priority, 'complete' => $complete && ! $unknown, 'completed_at' => $savedAt,
        ]);
        foreach ($protocol->questions as $question) {
            if (! $asked->contains('id', $question->id)) {
                $screening->answers()->create([
                    'screening_question_id' => $question->id, 'question_prompt' => $question->prompt, 'answer' => '', 'answer_status' => 'skipped',
                    'question_version' => $protocol->version, 'saved_at' => $savedAt, 'recorded_by' => $user->id,
                ]);

                continue;
            }

            $answer = trim((string) ($answers->get($question->id)['answer'] ?? ''));
            $screening->answers()->create([
                'screening_question_id' => $question->id, 'question_prompt' => $question->prompt, 'answer' => $answer,
                'answer_status' => match (true) {
                    $answer === '' => 'unanswered',
                    strtolower($answer) === 'unknown' => 'unknown',
                    default => 'answered',
                },
                'question_version' => $protocol->version, 'saved_at' => $savedAt, 'recorded_by' => $user->id,
            ]);
        }
        ScreeningDraft::query()->where('case_id', $case->id)->where('screening_protocol_id', $protocol->id)->delete();
        $case->update(['priority' => $priority, 'status' => $priority === 'Review' ? 'clinical_review' : 'open', 'disposition' => null, 'next_step' => null]);
    }

    /**
     * Build the per-answer autosave snapshot. Unasked answers keep their text so
     * a conditional trigger can be reversed without losing what the caller said.
     *
     * @param  Collection<int|string, array<string, mixed>>  $answers
     * @return list<array<string, mixed>>
     */
    private function draftAnswers(ScreeningProtocol $protocol, Collection $answers, User $user, CarbonInterface $savedAt): array
    {
        $asked = $protocol->questions->filter(fn (ScreeningQuestion $question): bool => $this->isAsked($question, $answers));

        return $protocol->questions->map(function (ScreeningQuestion $question) use ($protocol, $answers, $asked, $user, $savedAt): array {
            $answer = trim((string) ($answers->get($question->id)['answer'] ?? ''));
            $isAsked = $asked->contains('id', $question->id);
            $this->require(! $isAsked || $answer === '' || $this->isValidAnswer($question, $answer), 'answers', 'Record a valid answer for "'.$question->prompt.'".');

            return [
                'question_id' => $question->id,
                'answer' => $answer,
                'answer_status' => match (true) {
                    ! $isAsked => 'skipped',
                    $answer === '' => 'unanswered',
                    strtolower($answer) === 'unknown' => 'unknown',
                    default => 'answered',
                },
                'question_version' => $protocol->version,
                'recorded_by' => $user->id,
                'saved_at' => $savedAt->toIso8601String(),
            ];
        })->values()->all();
    }

    /** @param array<string, mixed> $data */
    private function note(CallerCase $case, User $user, array $data): void
    {
        $case->notes()->create(['recorded_by' => $user->id, 'body' => $data['body']]);
    }

    /** @param array<string, mixed> $data */
    private function close(CallerCase $case, User $user, array $data): void
    {
        $screening = $case->screenings()->latest('id')->first();
        $this->require($screening !== null, 'screening', 'Record a safety screening before choosing a disposition.');
        $protocol = $this->protocol((int) $screening->screening_protocol_id);
        $priority = $case->priority;
        $disposition = self::DISPOSITIONS[$data['disposition']];
        $this->require(in_array($priority, $disposition['priorities'], true), 'disposition', 'This disposition does not match the recorded safety priority.');
        $this->require($priority === 'P1' || $screening->complete || $priority === 'Review', 'screening', 'Complete the safety screen before closing a routine case.');
        if ($priority === 'P1') {
            $this->require(($data['emergency_action_confirmed'] ?? false) && ($data['supervisor_notified'] ?? false), 'emergency_action_confirmed', 'Confirm the emergency action and supervisor notification. Never wait for dentist availability.');
        }
        if (in_array($priority, ['P2', 'P3', 'S'], true)) {
            $this->require(filled($case->concern), 'concern', 'Capture the caller-reported concern before closure.');
        }
        if ($data['disposition'] === 'callback_requested') {
            $this->require(filled($case->callback_number), 'callback_number', 'A callback request requires a confirmed number.');
        }
        if ($data['disposition'] === 'faq_resolved') {
            $this->require($case->concern_type === 'service_information', 'disposition', 'A personal clinical concern cannot be closed as a service FAQ.');
            $this->require(($data['faq_confirmed'] ?? false) && filled($protocol->faq_answer), 'faq_confirmed', 'Confirm that the service FAQ answered the request.');
        }
        $review = $priority === 'Review';
        $case->update([
            'disposition' => $data['disposition'], 'next_step' => $data['next_step'],
            'status' => $review ? 'clinical_review' : 'closed',
            'closed_at' => $review ? null : now(), 'closed_by' => $review ? null : $user->id,
        ]);
    }

    /**
     * Conditional questions are only asked when the earlier answer triggers them.
     *
     * @param  Collection<int|string, array<string, mixed>>  $answers
     */
    private function isAsked(ScreeningQuestion $question, Collection $answers): bool
    {
        if ($question->conditional_question_id === null) {
            return true;
        }

        $trigger = strtolower(trim((string) ($answers->get($question->conditional_question_id)['answer'] ?? '')));

        return $trigger !== '' && $trigger === strtolower(trim((string) $question->conditional_answer));
    }

    private function isPositiveAnswer(string $answer): bool
    {
        $answer = strtolower(trim($answer));

        return $answer !== '' && str_starts_with($answer, 'yes');
    }

    private function isValidAnswer(ScreeningQuestion $question, string $answer): bool
    {
        if ($answer === '') {
            return true;
        }

        return match ($question->type) {
            'choice' => in_array($answer, $question->options ?? [], true),
            'text', 'paragraph' => mb_strlen($answer) <= 2000,
            default => in_array(strtolower($answer), $question->type === 'yes_no' ? ['yes', 'no'] : ['yes', 'no', 'unknown'], true),
        };
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
