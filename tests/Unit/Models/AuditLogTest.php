<?php

declare(strict_types=1);

use App\Models\AuditLog;

describe('AuditLog Model', function () {
    it('can create audit log', function () {
        $log = AuditLog::create([
            'table_name' => 'users',
            'record_id' => 1,
            'action' => 'create',
        ]);

        expect($log)->toBeInstanceOf(AuditLog::class);
    });
});
