<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class IdempotencyService
{
    public function begin(Request $request, ?string $tenantId, ?string $userId): array
    {
        $idempotencyKey = (string) $request->header('X-Idempotency-Key', '');
        if ($idempotencyKey === '') {
            return ['enabled' => false];
        }

        $requestBody = Arr::sortRecursive($request->all());
        $requestHash = hash('sha256', json_encode($requestBody));
        $scopeHash = hash(
            'sha256',
            implode('|', [
                $tenantId ?? 'global',
                $userId ?? 'anonymous',
                strtoupper($request->method()),
                '/'.ltrim($request->path(), '/'),
                $idempotencyKey,
            ])
        );

        $record = IdempotencyKey::query()->where('scope_hash', $scopeHash)->first();
        if (! $record) {
            $record = IdempotencyKey::query()->create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'scope_hash' => $scopeHash,
                'idempotency_key' => $idempotencyKey,
                'method' => strtoupper($request->method()),
                'path' => '/'.ltrim($request->path(), '/'),
                'request_hash' => $requestHash,
            ]);

            return ['enabled' => true, 'replay' => false, 'record' => $record];
        }

        if ($record->request_hash !== $requestHash) {
            return ['enabled' => true, 'conflict' => true];
        }

        if (! is_null($record->response_status)) {
            return ['enabled' => true, 'replay' => true, 'record' => $record];
        }

        return ['enabled' => true, 'in_progress' => true];
    }

    public function storeResponse(IdempotencyKey $record, int $status, array $body): void
    {
        $record->response_status = $status;
        $record->response_body = $body;
        $record->response_recorded_at = now();
        $record->save();
    }
}
