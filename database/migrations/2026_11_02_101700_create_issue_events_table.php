<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('issue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Issue::class)->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('mcp_token_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at', 3);

            $table->index(['issue_id', 'occurred_at']);
        });

        // What can be told of existing issues: when they first happened, and their last turns.
        $rows = [];

        foreach (DB::table('issues')->select(['id', 'first_seen_at', 'regressed_at', 'resolved_at', 'ignored_at', 'escalated_at'])->cursor() as $issue) {
            $rows[] = ['issue_id' => $issue->id, 'type' => 'created', 'data' => null, 'occurred_at' => $issue->first_seen_at];

            foreach (['regressed_at' => 'regressed', 'escalated_at' => 'escalated'] as $column => $type) {
                if ($issue->{$column} !== null) {
                    $rows[] = ['issue_id' => $issue->id, 'type' => $type, 'data' => null, 'occurred_at' => $issue->{$column}];
                }
            }

            foreach (['resolved_at' => 'resolved', 'ignored_at' => 'ignored'] as $column => $status) {
                if ($issue->{$column} !== null) {
                    $rows[] = ['issue_id' => $issue->id, 'type' => 'status_changed', 'data' => json_encode(['to' => $status]), 'occurred_at' => $issue->{$column}];
                }
            }

            if (count($rows) >= 500) {
                DB::table('issue_events')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('issue_events')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_events');
    }
};
