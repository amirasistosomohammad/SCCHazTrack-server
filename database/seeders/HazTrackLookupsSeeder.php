<?php

namespace Database\Seeders;

use App\Models\HazardCategory;
use App\Models\HazardStatus;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Seeder;

class HazTrackLookupsSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            ['key' => 'pending', 'label' => 'Pending', 'sort_order' => 10, 'is_terminal' => false],
            ['key' => 'under_review', 'label' => 'Under Review', 'sort_order' => 20, 'is_terminal' => false],
            ['key' => 'in_progress', 'label' => 'In Progress', 'sort_order' => 30, 'is_terminal' => false],
            ['key' => 'on_hold', 'label' => 'On Hold', 'sort_order' => 40, 'is_terminal' => false],
            ['key' => 'resolved', 'label' => 'Resolved', 'sort_order' => 50, 'is_terminal' => false],
            ['key' => 'closed', 'label' => 'Closed', 'sort_order' => 60, 'is_terminal' => true],
        ];

        foreach ($statuses as $status) {
            HazardStatus::query()->updateOrCreate(
                ['key' => $status['key']],
                $status
            );
        }

        $categories = [
            ['name' => 'Electrical', 'description' => null, 'is_active' => true],
            ['name' => 'Sanitation', 'description' => null, 'is_active' => true],
            ['name' => 'Structural', 'description' => null, 'is_active' => true],
            ['name' => 'Security', 'description' => null, 'is_active' => true],
            ['name' => 'Environmental', 'description' => null, 'is_active' => true],
            ['name' => 'Other', 'description' => null, 'is_active' => true],
        ];

        foreach ($categories as $category) {
            HazardCategory::query()->updateOrCreate(
                ['name' => $category['name']],
                $category
            );
        }

        // Campus seed (admin-managed later). These are the only campus values we want in the system.
        $campuses = [
            'Elementary Campus',
            'Junior High School Campus',
            'San Francisco Campus',
            'Buenavista Campus',
        ];

        $campusCount = count($campuses);

        // Ensure each allowed campus exists and is active.
        foreach ($campuses as $name) {
            Location::query()->updateOrCreate(
                ['name' => $name],
                ['description' => null, 'parent_id' => null, 'is_active' => true]
            );
        }

        // Rename any non-allowed location names into one of the allowed campuses.
        // This removes legacy values from existing hazard reports without breaking FKs.
        $nonAllowedLocations = Location::query()
            ->whereNotIn('name', $campuses)
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($nonAllowedLocations as $idx => $loc) {
            $mappedName = $campuses[$idx % $campusCount];
            Location::query()->where('id', $loc->id)->update([
                'name' => $mappedName,
                'parent_id' => null,
                'is_active' => true,
            ]);
        }

        // Deactivate duplicate location rows that now share the same allowed name.
        foreach ($campuses as $name) {
            $ids = Location::query()->where('name', $name)->orderBy('id')->pluck('id')->all();
            $keepId = $ids[0] ?? null;
            if (! $keepId) continue;
            Location::query()
                ->where('name', $name)
                ->where('id', '!=', $keepId)
                ->update(['is_active' => false]);
        }

        // Normalize existing user campus values so submit/profile prefill maps correctly.
        $nonAllowedUsers = User::query()
            ->whereNotNull('campus')
            ->whereNotIn('campus', $campuses)
            ->orderBy('id')
            ->get(['id', 'campus']);

        foreach ($nonAllowedUsers as $idx => $u) {
            $mappedName = $campuses[$idx % $campusCount];
            User::query()->where('id', $u->id)->update(['campus' => $mappedName]);
        }
    }
}

