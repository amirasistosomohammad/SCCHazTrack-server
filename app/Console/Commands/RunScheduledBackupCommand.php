<?php

namespace App\Console\Commands;

use App\Services\BackupScheduleStore;
use App\Services\DatabaseBackupExporter;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunScheduledBackupCommand extends Command
{
    protected $signature = 'haztrack:scheduled-backup';

    protected $description = 'Run an automated SQL backup when the configured schedule is due';

    public function handle(BackupScheduleStore $scheduleStore, DatabaseBackupExporter $exporter): int
    {
        $schedule = $scheduleStore->read();
        if (! $scheduleStore->isDue($schedule)) {
            return self::SUCCESS;
        }

        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $name = 'haztrack-scheduled-'.now('Asia/Manila')->format('Y-m-d-His').'.sql';
        $path = $dir.DIRECTORY_SEPARATOR.$name;

        try {
            $exporter->dumpToFile($path);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $ranAt = Carbon::now('Asia/Manila');
        $frequency = (string) ($schedule['frequency'] ?? 'off');
        $runAt = (string) ($schedule['run_at_time'] ?? '02:00');

        $next = $scheduleStore->computeNextAfterSuccessfulRun($frequency, $runAt, $ranAt);

        $scheduleStore->write([
            'last_run_at' => $ranAt->toIso8601String(),
            'next_run_at' => $next,
        ]);

        $this->info('Scheduled backup written: '.$name);

        return self::SUCCESS;
    }
}
