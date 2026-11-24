<?php

use App\Http\Controllers\Admin\AdministrationController;
use App\Http\Controllers\Admin\AmiHealthController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CdrCelController;
use App\Http\Controllers\Admin\ConnectionHealthController;
use App\Http\Controllers\Admin\IntegrationLogController;
use App\Http\Controllers\Admin\PbxConnectionController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\ServiceHourController;
use App\Http\Controllers\Admin\SessionController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\StructuredLogController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AgentCallController;
use App\Http\Controllers\AgentWorkspaceController;
use App\Http\Controllers\CallbackController;
use App\Http\Controllers\CallControlController;
use App\Http\Controllers\CallJourneyController;
use App\Http\Controllers\CallTimeController;
use App\Http\Controllers\CorrelationExceptionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DentistController;
use App\Http\Controllers\DidCallController;
use App\Http\Controllers\ExpDashboardController;
use App\Http\Controllers\GuideController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\KnowledgeBankController;
use App\Http\Controllers\QualityAssuranceController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScreeningProtocolController;
use App\Http\Controllers\ShiftHandoverController;
use App\Http\Controllers\TrainingController;
use App\Http\Controllers\TransferController;
use App\Services\GuideLibrary;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('administration', [AdministrationController::class, 'index'])->name('administration.index');

    // Operating manuals. Each role manual is gated by its own `view … guides`
    // permission (see GuideLibrary), so the menu and the controller agree.
    Route::get('guides', [GuideController::class, 'index'])->name('guides.index');
    Route::get('guides/{guide}', [GuideController::class, 'show'])
        ->whereIn('guide', [GuideLibrary::AGENT, GuideLibrary::VIEWER, GuideLibrary::SUPER_ADMIN])
        ->name('guides.show');

    Route::get('transfers', [TransferController::class, 'index'])->name('transfers.index');
    Route::get('transfers/{attempt}', [TransferController::class, 'show'])->name('transfers.show');
    Route::post('transfers/{attempt}/events', [TransferController::class, 'record'])->name('transfers.record');

    Route::get('workspace', [AgentWorkspaceController::class, 'index'])->name('workspace.index');
    Route::post('workspace', [AgentWorkspaceController::class, 'store'])->name('workspace.store');
    Route::get('cases', [AgentWorkspaceController::class, 'index'])->name('cases.index');
    Route::get('cases/assignments', [ShiftHandoverController::class, 'assignments'])->name('cases.assignments');
    Route::post('cases/{case}/assignment', [ShiftHandoverController::class, 'storeAssignment'])->name('cases.assignments.store');
    Route::get('cases/handover', [ShiftHandoverController::class, 'handover'])->name('cases.handover');
    Route::post('cases/handover', [ShiftHandoverController::class, 'storeHandover'])->name('cases.handover.store');
    Route::get('cases/{case}/call-control', [CallControlController::class, 'show'])->name('call-control.show');
    Route::post('cases/{case}/call-control', [CallControlController::class, 'store'])->middleware('throttle:30,1')->name('call-control.store');
    Route::get('cases/{case}', [AgentWorkspaceController::class, 'show'])->name('workspace.show');
    Route::patch('cases/{case}', [AgentWorkspaceController::class, 'update'])->name('workspace.update');
    Route::patch('cases/{case}/screening-draft', [AgentWorkspaceController::class, 'saveDraft'])->name('workspace.draft');

    // Callbacks and referrals (Phase 8)
    Route::get('callbacks', [CallbackController::class, 'index'])->name('callbacks.index');
    Route::post('callbacks', [CallbackController::class, 'store'])->name('callbacks.store');
    Route::post('callbacks/{callback}/attempts', [CallbackController::class, 'attempt'])->name('callbacks.attempts.store');
    Route::patch('callbacks/{callback}', [CallbackController::class, 'update'])->name('callbacks.update');
    Route::post('callbacks/{callback}/cancel', [CallbackController::class, 'cancel'])->name('callbacks.cancel');

    Route::get('referrals', [ReferralController::class, 'index'])->name('referrals.index');
    Route::post('referrals', [ReferralController::class, 'store'])->name('referrals.store');
    Route::patch('referrals/{referral}/action', [ReferralController::class, 'action'])->name('referrals.action');
    Route::patch('referrals/{referral}/outcome', [ReferralController::class, 'outcome'])->name('referrals.outcome');
    Route::post('referrals/{referral}/cancel', [ReferralController::class, 'cancel'])->name('referrals.cancel');

    // Knowledge bank, approvals and offline guidance (Phase 9)
    Route::get('knowledge-bank', [KnowledgeBankController::class, 'index'])->name('knowledge-bank.index');
    Route::get('knowledge-bank/offline', [KnowledgeBankController::class, 'offline'])->name('knowledge-bank.offline');
    Route::post('knowledge-bank/articles', [KnowledgeBankController::class, 'storeArticle'])->name('knowledge-bank.articles.store');
    Route::post('knowledge-bank/articles/{article}/retire', [KnowledgeBankController::class, 'retireArticle'])->name('knowledge-bank.articles.retire');
    Route::post('knowledge-bank/versions', [KnowledgeBankController::class, 'storeVersion'])->name('knowledge-bank.versions.store');
    Route::patch('knowledge-bank/versions/{version}', [KnowledgeBankController::class, 'updateVersion'])->name('knowledge-bank.versions.update');
    Route::post('knowledge-bank/versions/{version}/review', [KnowledgeBankController::class, 'review'])->name('knowledge-bank.versions.review');
    Route::post('knowledge-bank/versions/{version}/decisions', [KnowledgeBankController::class, 'decide'])->name('knowledge-bank.versions.decide');
    Route::post('knowledge-bank/versions/{version}/publish', [KnowledgeBankController::class, 'publish'])->name('knowledge-bank.versions.publish');
    Route::post('knowledge-bank/versions/{version}/retire', [KnowledgeBankController::class, 'retireVersion'])->name('knowledge-bank.versions.retire');
    Route::post('knowledge-bank/translations', [KnowledgeBankController::class, 'storeTranslation'])->name('knowledge-bank.translations.store');
    Route::patch('knowledge-bank/translations/{translation}', [KnowledgeBankController::class, 'updateTranslation'])->name('knowledge-bank.translations.update');
    Route::post('knowledge-bank/translations/{translation}/approval', [KnowledgeBankController::class, 'approveTranslation'])->name('knowledge-bank.translations.approve');
    Route::post('knowledge-bank/translations/{translation}/retire', [KnowledgeBankController::class, 'retireTranslation'])->name('knowledge-bank.translations.retire');

    // Quality assurance, training and incidents (Phase 10)
    Route::get('quality-assurance', [QualityAssuranceController::class, 'index'])->name('quality-assurance.index');
    Route::post('quality-assurance/reviews', [QualityAssuranceController::class, 'store'])->name('quality-assurance.reviews.store');
    Route::patch('quality-assurance/reviews/{review}', [QualityAssuranceController::class, 'update'])->name('quality-assurance.reviews.update');
    Route::post('quality-assurance/reviews/{review}/finalise', [QualityAssuranceController::class, 'finalise'])->name('quality-assurance.reviews.finalise');
    Route::post('quality-assurance/reviews/{review}/coaching', [QualityAssuranceController::class, 'coaching'])->name('quality-assurance.reviews.coaching');
    Route::post('quality-assurance/reviews/{review}/calibrations', [QualityAssuranceController::class, 'storeCalibration'])->name('quality-assurance.calibrations.store');
    Route::post('quality-assurance/calibrations/{calibration}/agreement', [QualityAssuranceController::class, 'agreeCalibration'])->name('quality-assurance.calibrations.agree');
    Route::get('training', [TrainingController::class, 'index'])->name('training.index');
    Route::post('training/modules', [TrainingController::class, 'storeModule'])->name('training.modules.store');
    Route::patch('training/modules/{module}', [TrainingController::class, 'updateModule'])->name('training.modules.update');
    Route::post('training/modules/{module}/publish', [TrainingController::class, 'publishModule'])->name('training.modules.publish');
    Route::post('training/modules/{module}/retire', [TrainingController::class, 'retireModule'])->name('training.modules.retire');
    Route::post('training/assessments', [TrainingController::class, 'storeAssessment'])->name('training.assessments.store');
    Route::post('training/certifications', [TrainingController::class, 'storeCertification'])->name('training.certifications.store');
    Route::post('training/certifications/{certification}/revoke', [TrainingController::class, 'revokeCertification'])->name('training.certifications.revoke');
    Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
    Route::post('incidents', [IncidentController::class, 'store'])->name('incidents.store');
    Route::post('incidents/{incident}/escalation', [IncidentController::class, 'escalate'])->name('incidents.escalate');
    Route::post('incidents/{incident}/evidence-access', [IncidentController::class, 'evidence'])->name('incidents.evidence');
    Route::post('incidents/{incident}/actions', [IncidentController::class, 'storeAction'])->name('incidents.actions.store');
    Route::patch('incidents/actions/{action}', [IncidentController::class, 'completeAction'])->name('incidents.actions.complete');
    Route::post('incidents/actions/{action}/verification', [IncidentController::class, 'verifyAction'])->name('incidents.actions.verify');
    Route::post('incidents/{incident}/resolution', [IncidentController::class, 'resolve'])->name('incidents.resolve');
    Route::post('incidents/{incident}/closure', [IncidentController::class, 'close'])->name('incidents.close');
    Route::get('screening-protocols', [ScreeningProtocolController::class, 'index'])->name('screening-protocols.index');
    Route::post('screening-protocols', [ScreeningProtocolController::class, 'store'])->name('screening-protocols.store');
    Route::post('screening-protocols/{protocol}/activation', [ScreeningProtocolController::class, 'activate'])->name('screening-protocols.activate');
    Route::delete('screening-protocols/{protocol}', [ScreeningProtocolController::class, 'retire'])->name('screening-protocols.retire');

    Route::get('/exp', [ExpDashboardController::class, 'index'])->name('exp.dashboard');
    Route::get('/exp/live', [ExpDashboardController::class, 'live'])->name('exp.live');
    Route::patch('/exp/preferences', [ExpDashboardController::class, 'preferences'])->name('exp.preferences');
    Route::get('live-operations/active-calls', [ExpDashboardController::class, 'activeCalls'])->name('live-operations.active-calls');
    Route::get('live-operations/transfers', [ExpDashboardController::class, 'transfers'])->name('live-operations.transfers');
    Route::get('live-operations/alerts', [ExpDashboardController::class, 'alerts'])->name('live-operations.alerts');
    Route::post('live-operations/alerts/{alert}/acknowledge', [ExpDashboardController::class, 'acknowledge'])->name('live-operations.alerts.acknowledge');

    // Inbound call views: the agent's own queue (IVR option 3 only) and the
    // full DID log with each caller's menu journey.
    Route::get('calls/agent', [AgentCallController::class, 'index'])->name('calls.agent');
    Route::get('calls/did', [DidCallController::class, 'index'])->name('calls.did');

    Route::get('live-operations/calls', [CallJourneyController::class, 'index'])->name('calls.index');
    Route::get('live-operations/calls/export', [CallJourneyController::class, 'export'])->name('calls.export');
    Route::get('live-operations/calls/{interaction}', [CallJourneyController::class, 'show'])->name('calls.show');
    Route::post('live-operations/calls/{interaction}/reconciliation', [CallJourneyController::class, 'reconcile'])->name('calls.reconcile');
    Route::get('diagnostics/correlation-exceptions', [CorrelationExceptionController::class, 'index'])->name('correlation-exceptions.index');

    // Operational reports (Phase 6). Every route needs `view reports`; the
    // executive summary additionally needs `view executive reports` and CSV
    // export needs `export reports`, so read-only monitoring can never export.
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::post('reports/{report}/runs', [ReportController::class, 'store'])->name('reports.store');
    Route::get('reports/{report}/export', [ReportController::class, 'export'])->name('reports.export');

    // Call time: total, wait, agent and dentist time, gated by `view call time`
    // so a read-only role can study timing without opening PBX telemetry tools.
    Route::prefix('call-time')->name('call-time.')->group(function () {
        Route::get('/', [CallTimeController::class, 'overview'])->name('overview');
        Route::get('agents', [CallTimeController::class, 'agents'])->name('agents');
        Route::get('dentists', [CallTimeController::class, 'dentists'])->name('dentists');
    });

    // Dentist directory and rostering routes (Phase 5)
    Route::prefix('dentists')->name('dentists.')->group(function () {
        Route::get('/', [DentistController::class, 'index'])->name('index');
        Route::get('selectable', [DentistController::class, 'selectable'])->name('selectable');
        Route::get('numbers', [DentistController::class, 'numbers'])->name('numbers');
        Route::get('credentials', [DentistController::class, 'credentials'])->name('credentials');
        Route::get('rosters', [DentistController::class, 'rosters'])->name('rosters');
        Route::post('rosters/generate', [DentistController::class, 'generateRosters'])->name('rosters.generate');
        Route::get('availability', [DentistController::class, 'availability'])->name('availability');
        Route::get('performance', [DentistController::class, 'performance'])->name('performance');

        Route::post('/', [DentistController::class, 'store'])->name('store');
        Route::patch('{dentist}', [DentistController::class, 'update'])->name('update');
        Route::delete('{dentist}', [DentistController::class, 'destroy'])->name('destroy');

        Route::post('{dentist}/numbers', [DentistController::class, 'storeNumber'])->name('numbers.store');
        Route::patch('{dentist}/numbers/{number}', [DentistController::class, 'updateNumber'])->name('numbers.update');
        Route::delete('{dentist}/numbers/{number}', [DentistController::class, 'destroyNumber'])->name('numbers.destroy');

        Route::post('{dentist}/credentials', [DentistController::class, 'storeCredential'])->name('credentials.store');
        Route::patch('{dentist}/credentials/{credential}', [DentistController::class, 'updateCredential'])->name('credentials.update');
        Route::delete('{dentist}/credentials/{credential}', [DentistController::class, 'destroyCredential'])->name('credentials.destroy');

        Route::post('{dentist}/rosters', [DentistController::class, 'storeRoster'])->name('rosters.store');
        Route::patch('{dentist}/rosters/{roster}', [DentistController::class, 'updateRoster'])->name('rosters.update');
        Route::delete('{dentist}/rosters/{roster}', [DentistController::class, 'destroyRoster'])->name('rosters.destroy');

        Route::post('{dentist}/availability', [DentistController::class, 'storeAvailability'])->name('availability.store');
        Route::delete('{dentist}/availability/{override}', [DentistController::class, 'destroyAvailability'])->name('availability.destroy');
    });

    // Administration routes (Phase 1 - Foundation and access)
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'edit', 'create']);
        Route::resource('roles', RoleController::class)->except(['show', 'edit', 'create']);
        Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
        Route::resource('settings', SettingController::class)->only(['index', 'update']);
        Route::resource('sessions', SessionController::class)->only(['index', 'destroy']);
        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');

        // PBX integration routes (Phase 2)
        Route::resource('pbx-connections', PbxConnectionController::class)->except(['show', 'edit', 'create']);
        Route::get('ami-health', [AmiHealthController::class, 'index'])->name('ami-health.index');
        Route::post('ami-health/check', [AmiHealthController::class, 'check'])->name('ami-health.check');
        Route::get('integration-logs', [IntegrationLogController::class, 'index'])->name('integration-logs.index');
        Route::get('connection-health', [ConnectionHealthController::class, 'index'])->name('connection-health.index');
        Route::get('structured-logs', [StructuredLogController::class, 'index'])->name('structured-logs.index');
        Route::resource('service-hours', ServiceHourController::class)->only(['index', 'store', 'update']);
        Route::get('cdr-cel', [CdrCelController::class, 'index'])->name('cdr-cel.index');
    });
});

require __DIR__.'/settings.php';
