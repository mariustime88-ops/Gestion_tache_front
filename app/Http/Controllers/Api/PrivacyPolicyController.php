<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PrivacyPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrivacyPolicyController extends Controller
{
    public function publicShow(): JsonResponse
    {
        $policy = PrivacyPolicy::query()
            ->where('is_published', true)
            ->first(['content', 'published_at']);

        return response()->json([
            'data' => [
                'content' => $policy?->content,
                'published_at' => $policy?->published_at,
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $policy = PrivacyPolicy::query()->firstOrCreate(
            ['id' => 1],
            ['content' => '', 'is_published' => false],
        );

        return response()->json(['data' => $policy]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:50000'],
        ]);

        $policy = PrivacyPolicy::query()->firstOrCreate(
            ['id' => 1],
            ['content' => '', 'is_published' => false],
        );
        $policy->update([
            'content' => trim($data['content']),
            'is_published' => true,
            'published_at' => now(),
            'published_by_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $policy->fresh()]);
    }
}
