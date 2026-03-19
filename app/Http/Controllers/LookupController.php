<?php

namespace App\Http\Controllers;

use App\Models\HazardCategory;
use App\Models\HazardStatus;
use App\Models\Location;
use Illuminate\Http\Request;

class LookupController extends Controller
{
    public function categories()
    {
        return response()->json([
            'data' => HazardCategory::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function locations()
    {
        return response()->json([
            'data' => Location::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function statuses()
    {
        return response()->json([
            'data' => HazardStatus::query()
                ->orderBy('sort_order')
                ->get(),
        ]);
    }
}

