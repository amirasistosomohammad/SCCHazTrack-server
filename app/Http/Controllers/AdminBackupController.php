<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\BackupScheduleStore;
use App\Services\DatabaseBackupExporter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminBackupController extends Controller
{
    private function denyUnlessAdmin(Request $request): ?\Illuminate\Http\JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->role !== User::ROLE_ADMIN) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return null;
    }

    public function schedule(Request $request, BackupScheduleStore $store)
    {
        if ($e = $this->denyUnlessAdmin($request)) {
            return $e;
        }
        $data = $store->read();
        $data['has_latest_file'] = $this->hasBackupFiles();

        return response()->json($data);
    }

    public function updateSchedule(Request $request, BackupScheduleStore $store)
    {
        if ($e = $this->denyUnlessAdmin($request)) {
            return $e;
        }

        $validated = $request->validate([
            'frequency' => ['required', 'in:off,daily,weekly'],
            'run_at_time' => ['required', 'date_format:H:i'],
        ]);

        $current = $store->read();
        $next = $validated['frequency'] === 'off'
            ? null
            : $store->computeInitialNextRun($validated['frequency'], $validated['run_at_time']);

        $store->write([
            'frequency' => $validated['frequency'],
            'run_at_time' => $validated['run_at_time'],
            'timezone' => 'Asia/Manila',
            'next_run_at' => $next,
            'last_run_at' => $current['last_run_at'] ?? null,
        ]);

        $out = $store->read();
        $out['has_latest_file'] = $this->hasBackupFiles();

        return response()->json($out);
    }

    public function listBackups(Request $request)
    {
        if ($e = $this->denyUnlessAdmin($request)) {
            return $e;
        }

        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            return response()->json(['backups' => []]);
        }

        $files = File::glob($dir.DIRECTORY_SEPARATOR.'*.sql') ?: [];
        $items = [];
        foreach ($files as $path) {
            if (! is_file($path)) {
                continue;
            }
            $items[] = [
                'filename' => basename($path),
                'created_at' => Carbon::createFromTimestamp((int) filemtime($path))->toIso8601String(),
            ];
        }

        usort($items, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return response()->json(['backups' => $items]);
    }

    public function download(Request $request, DatabaseBackupExporter $exporter)
    {
        if ($e = $this->denyUnlessAdmin($request)) {
            return $e;
        }

        try {
            $body = $exporter->captureDumpToString();
        } catch (\Throwable $th) {
            return response()->json([
                'message' => $th->getMessage() ?: 'Backup failed.',
            ], 422);
        }

        $name = 'haztrack-backup-'.now('Asia/Manila')->format('Y-m-d-His').'.sql';

        return response($body, 200, [
            'Content-Type' => 'application/sql',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    public function downloadLatest(Request $request)
    {
        if ($e = $this->denyUnlessAdmin($request)) {
            return $e;
        }

        $latest = $this->latestBackupPath();
        if ($latest === null) {
            return response()->json(['message' => 'No backup file available.'], 404);
        }

        return $this->streamFile($latest);
    }

    public function downloadFile(Request $request, string $filename)
    {
        if ($e = $this->denyUnlessAdmin($request)) {
            return $e;
        }

        if (! $this->isSafeBackupFilename($filename)) {
            return response()->json(['message' => 'Invalid file.'], 400);
        }

        $path = storage_path('app/backups'.DIRECTORY_SEPARATOR.$filename);
        $real = realpath($path);
        $base = realpath(storage_path('app/backups'));
        if ($real === false || $base === false || ! str_starts_with($real, $base) || ! is_file($real)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return $this->streamFile($real);
    }

    private function streamFile(string $absolutePath): StreamedResponse
    {
        $name = basename($absolutePath);

        return response()->streamDownload(function () use ($absolutePath) {
            $h = fopen($absolutePath, 'rb');
            if ($h === false) {
                return;
            }
            try {
                fpassthru($h);
            } finally {
                fclose($h);
            }
        }, $name, [
            'Content-Type' => 'application/sql',
        ]);
    }

    private function hasBackupFiles(): bool
    {
        return $this->latestBackupPath() !== null;
    }

    private function latestBackupPath(): ?string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            return null;
        }
        $files = File::glob($dir.DIRECTORY_SEPARATOR.'*.sql') ?: [];
        $latest = null;
        $latestM = -1;
        foreach ($files as $path) {
            if (! is_file($path)) {
                continue;
            }
            $m = (int) filemtime($path);
            if ($m > $latestM) {
                $latestM = $m;
                $latest = $path;
            }
        }

        return $latest;
    }

    private function isSafeBackupFilename(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9._-]+\.sql$/', $name);
    }
}
