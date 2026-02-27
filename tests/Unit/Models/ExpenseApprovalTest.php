<?php

declare(strict_types=1);

use App\Models\ExpenseApproval;
use App\Models\ExpenseRequest;
use App\Models\User;

describe('ExpenseApproval Model', function () {
    it('can be created', function () {
        $request = ExpenseRequest::factory()->create();
        $approval = ExpenseApproval::create([
            'expense_request_id' => $request->id,
            'actor_id' => User::factory()->create()->id,
            'actor_role' => 'director',
            'action' => 'approved',
        ]);

        expect($approval->expense_request_id)->toBe($request->id);
    });
});

