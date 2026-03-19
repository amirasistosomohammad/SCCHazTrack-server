<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Normalize legacy `new` status into the `pending` status.
        $openId = DB::table('hazard_statuses')->where('key', 'pending')->value('id');
        $newId = DB::table('hazard_statuses')->where('key', 'new')->value('id');

        if (! $newId) {
            return;
        }

        // If `pending` doesn't exist yet, rename `new` -> `pending`.
        if (! $openId) {
            DB::table('hazard_statuses')->where('id', $newId)->update([
                'key' => 'pending',
                'label' => 'Pending',
            ]);
            return;
        }

        // Otherwise, migrate references from `new` to `pending` and remove `new`.
        DB::table('hazard_reports')->where('current_status_id', $newId)->update([
            'current_status_id' => $openId,
        ]);

        DB::table('hazard_status_histories')->where('to_status_id', $newId)->update([
            'to_status_id' => $openId,
        ]);

        DB::table('hazard_status_histories')->where('from_status_id', $newId)->update([
            'from_status_id' => $openId,
        ]);

        DB::table('hazard_statuses')->where('id', $newId)->delete();
    }

    public function down(): void
    {
        // No-op: we don't want to re-introduce `new` once normalized.
    }
};

