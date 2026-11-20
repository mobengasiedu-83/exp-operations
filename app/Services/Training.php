<?php

namespace App\Services;

use App\Models\Certification;
use App\Models\TrainingAssessment;
use App\Models\TrainingModule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Training
{
    /** @var list<int> */
    public const DAYS = [1, 2, 3, 4, 5];

    /** @var list<string> */
    public const MODULE_STATUSES = TrainingModule::STATUSES;

    /** @var list<string> */
    public const ASSESSMENT_KINDS = TrainingAssessment::KINDS;

    public const DEFAULT_PASS_MARK = 80;

    public const CERTIFICATION_MONTHS = 12;

    /** @param array<string, mixed> $data */
    public function createModule(User $author, array $data): TrainingModule
    {
        return DB::transaction(function () use ($author, $data): TrainingModule {
            $day = (int) $data['day'];
            $this->require(in_array($day, self::DAYS, true), 'day', 'Choose one of the five curriculum days.');
            $this->require(
                ! TrainingModule::query()->where('day', $day)->where('status', '!=', 'retired')->exists(),
                'day',
                'This curriculum day already has a module. Edit it instead of creating another.',
            );
            $module = TrainingModule::query()->create([
                'day' => $day,
                'title' => $data['title'],
                'objectives' => $data['objectives'],
                'content' => $data['content'],
                'requires_role_play' => (bool) ($data['requires_role_play'] ?? true),
                'pass_mark' => (int) ($data['pass_mark'] ?? self::DEFAULT_PASS_MARK),
                'status' => 'draft',
                'authored_by' => $author->id,
                'revision' => 1,
            ]);
            activity('training')->causedBy($author)->performedOn($module)->withProperties([
                'operation' => 'module_drafted', 'day' => $day,
            ])->log('Training module drafted');

            return $module->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function updateModule(User $author, TrainingModule $module, array $data): TrainingModule
    {
        return DB::transaction(function () use ($author, $module, $data): TrainingModule {
            $module = TrainingModule::query()->lockForUpdate()->findOrFail($module->id);
            $this->require($module->isDraft(), 'revision', 'Only draft modules can be edited.');
            $this->require((int) $data['revision'] === $module->revision, 'revision', 'This module changed. Reload before saving.');
            $module->update([
                'title' => $data['title'],
                'objectives' => $data['objectives'],
                'content' => $data['content'],
                'requires_role_play' => (bool) ($data['requires_role_play'] ?? true),
                'pass_mark' => (int) ($data['pass_mark'] ?? self::DEFAULT_PASS_MARK),
                'revision' => $module->revision + 1,
            ]);
            activity('training')->causedBy($author)->performedOn($module)->withProperties([
                'operation' => 'module_updated', 'revision' => $module->revision,
            ])->log('Training module updated');

            return $module->refresh();
        });
    }

    public function publishModule(User $publisher, TrainingModule $module): TrainingModule
    {
        return DB::transaction(function () use ($publisher, $module): TrainingModule {
            $module = TrainingModule::query()->lockForUpdate()->findOrFail($module->id);
            $this->require($module->isDraft(), 'status', 'Only draft modules can be published.');
            $this->require(trim($module->objectives) !== '', 'objectives', 'Add the learning objectives before publishing.');
            $this->require(trim($module->content) !== '', 'content', 'Add the curriculum content before publishing.');
            $module->update([
                'status' => 'published',
                'published_by' => $publisher->id,
                'published_at' => now(),
                'revision' => $module->revision + 1,
            ]);
            activity('training')->causedBy($publisher)->performedOn($module)->withProperties([
                'operation' => 'module_published', 'day' => $module->day,
            ])->log('Training module published');

            return $module->refresh();
        });
    }

    public function retireModule(User $manager, TrainingModule $module): TrainingModule
    {
        return DB::transaction(function () use ($manager, $module): TrainingModule {
            $module = TrainingModule::query()->lockForUpdate()->findOrFail($module->id);
            $this->require($module->status !== 'retired', 'status', 'This module is already retired.');
            $module->update([
                'status' => 'retired',
                'retired_at' => now(),
                'revision' => $module->revision + 1,
            ]);
            activity('training')->causedBy($manager)->performedOn($module)->withProperties([
                'operation' => 'module_retired', 'day' => $module->day,
            ])->log('Training module retired');

            return $module->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function recordAssessment(User $assessor, array $data): TrainingAssessment
    {
        return DB::transaction(function () use ($assessor, $data): TrainingAssessment {
            $module = TrainingModule::query()->lockForUpdate()->findOrFail((int) $data['training_module_id']);
            $this->require($module->isPublished(), 'training_module_id', 'Only published modules can be assessed.');
            $candidate = User::query()->active()->find((int) $data['user_id']);
            $this->require($candidate instanceof User, 'user_id', 'Choose an active trainee.');
            $this->require($candidate->id !== $assessor->id, 'user_id', 'An assessor cannot assess their own training.');
            $kind = (string) $data['kind'];
            $this->require(in_array($kind, self::ASSESSMENT_KINDS, true), 'kind', 'Choose a supported assessment type.');
            $score = (int) $data['score'];
            $this->require($score >= 0 && $score <= 100, 'score', 'Enter an assessment score between 0 and 100.');
            $assessment = TrainingAssessment::query()->create([
                'training_module_id' => $module->id,
                'user_id' => $candidate->id,
                'kind' => $kind,
                'score' => $score,
                'pass_mark' => $module->pass_mark,
                'result' => $score >= $module->pass_mark ? 'pass' : 'fail',
                'assessed_by' => $assessor->id,
                'assessed_at' => now(),
                'note' => $data['note'] ?? null,
            ]);
            activity('training')->causedBy($assessor)->performedOn($assessment)->withProperties([
                'operation' => 'assessment_recorded', 'module_id' => $module->id, 'user_id' => $candidate->id,
                'kind' => $kind, 'score' => $score, 'result' => $assessment->result,
            ])->log('Training assessment recorded');

            return $assessment->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function certify(User $authoriser, User $candidate, array $data): Certification
    {
        return DB::transaction(function () use ($authoriser, $candidate, $data): Certification {
            $candidate = User::query()->lockForUpdate()->findOrFail($candidate->id);
            $this->require($candidate->active, 'user_id', 'Certification is limited to active operators.');
            $this->require(
                ! $candidate->certifications()->current()->exists(),
                'user_id',
                'This operator already holds an active live-call certification.',
            );
            $progress = $this->curriculumProgress($candidate);
            $incomplete = [];
            $snapshot = [];
            foreach ($progress as $day) {
                if (! $day['complete']) {
                    $incomplete[] = 'Day '.$day['day'];
                }
                $snapshot[] = [
                    'day' => $day['day'],
                    'module_id' => $day['module_id'],
                    'title' => $day['title'],
                    'knowledge' => $day['knowledge']['score'] ?? null,
                    'role_play' => $day['role_play']['score'] ?? null,
                ];
            }
            $this->require(
                $incomplete === [],
                'user_id',
                'The five-day curriculum is incomplete: '.implode(', ', $incomplete).'.',
            );
            $issued = CarbonImmutable::parse((string) ($data['issued_at'] ?? now()->toDateString()))->startOfDay();
            $expires = isset($data['expires_at']) && $data['expires_at'] !== ''
                ? CarbonImmutable::parse((string) $data['expires_at'])->startOfDay()
                : $issued->addMonths(self::CERTIFICATION_MONTHS);
            $this->require($expires->greaterThan($issued), 'expires_at', 'The certification expiry must be after the issue date.');
            $certification = $candidate->certifications()->create([
                'status' => 'active',
                'issued_at' => $issued,
                'expires_at' => $expires,
                'authorised_by' => $authoriser->id,
                'reference' => $data['reference'],
                'curriculum_snapshot' => $snapshot,
                'revision' => 1,
            ]);
            activity('training')->causedBy($authoriser)->performedOn($certification)->withProperties([
                'operation' => 'certified', 'user_id' => $candidate->id, 'expires_at' => $expires->toDateString(),
                'reference' => $data['reference'],
            ])->log('Live-call certification issued');

            return $certification->refresh();
        });
    }

    public function revoke(User $authoriser, Certification $certification, string $reason): Certification
    {
        return DB::transaction(function () use ($authoriser, $certification, $reason): Certification {
            $certification = Certification::query()->lockForUpdate()->findOrFail($certification->id);
            $this->require($certification->status === 'active', 'status', 'Only active certifications can be revoked.');
            $this->require(trim($reason) !== '', 'revocation_reason', 'Record why the certification is being withdrawn.');
            $certification->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'revoked_by' => $authoriser->id,
                'revocation_reason' => $reason,
                'revision' => $certification->revision + 1,
            ]);
            activity('training')->causedBy($authoriser)->performedOn($certification)->withProperties([
                'operation' => 'certification_revoked', 'user_id' => $certification->user_id,
            ])->log('Live-call certification revoked');

            return $certification->refresh();
        });
    }

    public function isAuthorisedForLiveCalls(User $user): bool
    {
        if ($user->can('authorise live calls')) {
            return true;
        }

        return $this->activeCertification($user) instanceof Certification;
    }

    public function activeCertification(User $user): ?Certification
    {
        $certification = $user->certifications()->current()->latest('expires_at')->latest('id')->first();

        return $certification instanceof Certification ? $certification : null;
    }

    /**
     * @return list<array{
     *     day: int,
     *     module_id: int|null,
     *     title: string|null,
     *     status: string,
     *     knowledge: array{score: int, result: string}|null,
     *     role_play: array{score: int, result: string}|null,
     *     complete: bool
     * }>
     */
    public function curriculumProgress(User $candidate): array
    {
        /** @var array<int, TrainingModule> $modules */
        $modules = [];
        foreach (TrainingModule::query()->where('status', 'published')->orderBy('day')->get() as $module) {
            $modules[$module->day] = $module;
        }
        /** @var array<string, TrainingAssessment> $latest */
        $latest = [];
        $assessments = TrainingAssessment::query()->where('user_id', $candidate->id)
            ->orderBy('assessed_at')->orderBy('id')->get();
        foreach ($assessments as $assessment) {
            $latest[$assessment->training_module_id.'|'.$assessment->kind] = $assessment;
        }
        $progress = [];
        foreach (self::DAYS as $day) {
            $module = $modules[$day] ?? null;
            $knowledge = $module instanceof TrainingModule ? ($latest[$module->id.'|knowledge'] ?? null) : null;
            $rolePlay = $module instanceof TrainingModule ? ($latest[$module->id.'|role_play'] ?? null) : null;
            $progress[] = [
                'day' => $day,
                'module_id' => $module instanceof TrainingModule ? $module->id : null,
                'title' => $module instanceof TrainingModule ? $module->title : null,
                'status' => $module instanceof TrainingModule ? $module->status : 'missing',
                'knowledge' => $knowledge instanceof TrainingAssessment
                    ? ['score' => $knowledge->score, 'result' => $knowledge->result]
                    : null,
                'role_play' => $rolePlay instanceof TrainingAssessment
                    ? ['score' => $rolePlay->score, 'result' => $rolePlay->result]
                    : null,
                'complete' => $module instanceof TrainingModule
                    && $knowledge instanceof TrainingAssessment
                    && $knowledge->passed()
                    && (! $module->requires_role_play || ($rolePlay instanceof TrainingAssessment && $rolePlay->passed())),
            ];
        }

        return $progress;
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
