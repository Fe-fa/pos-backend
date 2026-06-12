<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Throwable;

class AuditLogService
{
    public function log(
        string $action,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $meta = null,
        ?int $storeId = null
    ): void {
        try {
            $request = request();
            $user    = $request instanceof Request ? $request->user() : null;

            AuditLog::create([
                'user_id'        => $user?->user_id,
                'store_id'       => $storeId,
                'auditable_type' => $auditable ? get_class($auditable) : 'system',
                'auditable_id'   => $auditable?->getKey(),
                'auditable_uuid' => $auditable?->uuid ?? null,
                'action'         => $action,
                'method'         => $request?->method(),
                'route'          => $request?->path(),
                'ip_address'     => $request?->ip(),
                'user_agent'     => $request?->userAgent(),
                'old_values'     => $oldValues,
                'new_values'     => $newValues,
                'meta'           => $meta,
                'created_at'     => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}