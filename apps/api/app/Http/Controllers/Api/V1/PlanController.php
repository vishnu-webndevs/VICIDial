<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->where('is_public', true)
            ->with('features')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $plans,
        ]);
    }
}
