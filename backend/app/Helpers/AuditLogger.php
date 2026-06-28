<?php

namespace App\Helpers;

use App\Models\AuditTrail;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    /**
     * Log cashier activity to database audit trail.
     */
    public static function log(string $action, string $description, ?int $storeId = null, ?int $userId = null): void
    {
        try {
            AuditTrail::create([
                'store_id' => $storeId ?? auth()->user()?->store_id,
                'user_id' => $userId ?? auth()->id(),
                'action' => $action,
                'description' => $description,
                'ip_address' => Request::ip(),
                'user_agent' => Request::userAgent(),
            ]);
        } catch (\Throwable $e) {
            // Silently log or handle to prevent breaking core app flow
            logger()->error('Gagal mencatat audit trail: ' . $e->getMessage());
        }
    }
}
