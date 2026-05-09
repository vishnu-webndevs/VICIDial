<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $query = MessageTemplate::query()->where('tenant_id', $tenant->id);

        if ($request->filled('channel')) {
            $query->where('channel', (string) $request->input('channel'));
        }

        if ($request->filled('category')) {
            $query->where('category', (string) $request->input('category'));
        }

        if ($request->filled('active')) {
            $query->where('is_active', (bool) $request->boolean('active'));
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            if ($q !== '') {
                $query->where(function ($sub) use ($q): void {
                    $sub->where('key', 'like', '%'.$q.'%')
                        ->orWhere('name', 'like', '%'.$q.'%')
                        ->orWhere('body', 'like', '%'.$q.'%');
                });
            }
        }

        $items = $query->orderBy('channel')->orderBy('key')->get();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'channel' => ['required', 'in:sms,whatsapp'],
            'category' => ['nullable', 'string', 'max:50'],
            'key' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $template = MessageTemplate::query()->create([
            'tenant_id' => $tenant->id,
            'channel' => (string) $validated['channel'],
            'category' => $validated['category'] ?? null,
            'key' => (string) $validated['key'],
            'name' => (string) $validated['name'],
            'body' => (string) $validated['body'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $template = MessageTemplate::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
            'category' => ['nullable', 'string', 'max:50'],
        ]);

        $template->fill($validated);
        $template->updated_by = $request->user()?->id;
        $template->save();

        return response()->json(['data' => $template]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $template = MessageTemplate::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $template->delete();

        return response()->json(['success' => true]);
    }
}
