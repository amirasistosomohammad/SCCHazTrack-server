<?php

namespace App\Services;

use Carbon\Carbon;

class BackupScheduleStore
{
    private const TZ = 'Asia/Manila';

    public function path(): string
    {
        $dir = storage_path('app/backup');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir.DIRECTORY_SEPARATOR.'schedule.json';
    }

    /** @return array{frequency: string, run_at_time: string, timezone: string, last_run_at: ?string, next_run_at: ?string} */
    public function read(): array
    {
        $path = $this->path();
        if (! is_readable($path)) {
            return $this->defaults();
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            return $this->defaults();
        }

        return array_merge($this->defaults(), array_intersect_key($raw, $this->defaults()));
    }

    public function write(array $data): void
    {
        $merged = array_merge($this->read(), $data);
        file_put_contents(
            $this->path(),
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return [
            'frequency' => 'off',
            'run_at_time' => '02:00',
            'timezone' => self::TZ,
            'last_run_at' => null,
            'next_run_at' => null,
        ];
    }

    public function computeInitialNextRun(string $frequency, string $runAtTime): ?string
    {
        if ($frequency === 'off') {
            return null;
        }
        $tz = self::TZ;
        $now = Carbon::now($tz);
        if ($frequency === 'daily') {
            $todayAt = $now->copy()->startOfDay()->setTimeFromTimeString($runAtTime);

            return ($todayAt->gt($now) ? $todayAt : $todayAt->copy()->addDay())->toIso8601String();
        }
        if ($frequency === 'weekly') {
            return $now->copy()->addWeek()->startOfDay()->setTimeFromTimeString($runAtTime)->toIso8601String();
        }

        return null;
    }

    public function computeNextAfterSuccessfulRun(string $frequency, string $runAtTime, Carbon $ranAt): ?string
    {
        if ($frequency === 'off') {
            return null;
        }
        $tz = self::TZ;
        $base = $ranAt->copy()->timezone($tz);
        if ($frequency === 'daily') {
            return $base->copy()->addDay()->startOfDay()->setTimeFromTimeString($runAtTime)->toIso8601String();
        }
        if ($frequency === 'weekly') {
            return $base->copy()->addWeek()->startOfDay()->setTimeFromTimeString($runAtTime)->toIso8601String();
        }

        return null;
    }

    public function isDue(array $schedule): bool
    {
        if (($schedule['frequency'] ?? 'off') === 'off') {
            return false;
        }
        $next = $schedule['next_run_at'] ?? null;
        if (! $next) {
            return false;
        }
        try {
            return Carbon::now(self::TZ)->gte(Carbon::parse((string) $next)->timezone(self::TZ));
        } catch (\Throwable) {
            return false;
        }
    }
}
