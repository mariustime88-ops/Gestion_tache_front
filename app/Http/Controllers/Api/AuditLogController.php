<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\PagePermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(PagePermissions::allows($user, 'audit'), 403);

        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:120'],
            'action' => ['sometimes', 'string', 'max:60'],
        ]);

        $query = AuditLog::with('user:id,name,role')->latest('id');

        if (isset($filters['action'])) {
            $query->where('action', 'like', $filters['action'].'%');
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($query) use ($search): void {
                $query->where('description', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($author) => $author->where('name', 'like', "%{$search}%"));
            });
        }

        return response()->json($query->paginate(30)->withQueryString());
    }
}
