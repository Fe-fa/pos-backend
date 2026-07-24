<?php

namespace App\Console\Commands;

use App\Services\ChequeReconciliationService;
use Illuminate\Console\Command;

class ReconcilePendingChequesCommand extends Command
{
    protected $signature = 'cheques:reconcile-pending';
    protected $description = 'Reconcile pending cheque deposits against the bank daily statement feed';

    public function __construct(private readonly ChequeReconciliationService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $entries = $this->service->pullDailyFeed();
        $result = $this->service->reconcileEntries($entries);

        $this->info('Cheque reconciliation complete.');
        $this->line('Updated: ' . $result['updated']);
        $this->line('Skipped: ' . $result['skipped']);

        foreach ($result['errors'] as $error) {
            $this->error($error);
        }

        return self::SUCCESS;
    }
}
