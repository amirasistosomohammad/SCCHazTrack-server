<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $pendingId = DB::table('hazard_statuses')->where('key', 'pending')->value('id');
        $openId = DB::table('hazard_statuses')->where('key', 'open')->value('id');
        $newId = DB::table('hazard_statuses')->where('key', 'new')->value('id');

        // If pending doesn't exist yet, prefer renaming `open` -> `pending`, else `new` -> `pending`.
        if (! $pendingId) {
            if ($openId) {
                DB::table('hazard_statuses')->where('id', $openId)->update([
                    'key' => 'pending',
                    'label' => 'Pending',
                ]);
                $pendingId = $openId;
                $openId = null;
            } elseif ($newId) {
                DB::table('hazard_statuses')->where('id', $newId)->update([
                    'key' => 'pending',
                    'label' => 'Pending',
                ]);
                $pendingId = $newId;
                $newId = null;
            }
        }

        if (! $pendingId) {
            // Nothing to migrate.
            return;
        }

        // Migrate any remaining references from `open`/`new` to `pending` and delete the old statuses.
        foreach (array_filter([$openId, $newId]) as $legacyId) {
            DB::table('hazard_reports')->where('current_status_id', $legacyId)->update([
                'current_status_id' => $pendingId,
            ]);

            DB::table('hazard_status_histories')->where('to_status_id', $legacyId)->update([
                'to_status_id' => $pendingId,
            ]);

            DB::table('hazard_status_histories')->where('from_status_id', $legacyId)->update([
                'from_status_id' => $pendingId,
            ]);

            DB::table('hazard_statuses')->where('id', $legacyId)->delete();
        }
    }

    public function down(): void
    {
        // No-op: we don't want to reintroduce legacy status keys.
    }
};

