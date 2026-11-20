<?php

namespace App\Console\Commands;

use App\Models\Interaction;
use App\Services\CallReconciler;
use Illuminate\Console\Command;

class ExpReconcileCalls extends Command
{
    protected $signature = 'exp:reconcile-calls {--interaction=} {--after=0} {--limit=500} {--pending : Only process pending completed interactions}';

    protected $description = 'Compare completed call legs with connection-scoped CDR/CEL evidence.';

    public function handle(CallReconciler $reconciler): int
    {
        $rows = Interaction::query()->where('status', 'completed')
            ->when($this->option('interaction'), fn ($query, $id) => $query->whereKey($id))
            ->when($this->option('pending'), fn ($query) => $query->where('reconciliation_status', 'pending'))
            ->where('id', '>', max(0, (int) $this->option('after')))
            ->orderBy('id')->limit(max(1, min(5000, (int) $this->option('limit'))))->get();
        foreach ($rows as $interaction) {
            $reconciler->reconcile($interaction);
        }
        $this->info('Reconciled '.$rows->count().' interactions. Last ID: '.($rows->last()->id ?? $this->option('after')).'.');

        return self::SUCCESS;
    }
}
