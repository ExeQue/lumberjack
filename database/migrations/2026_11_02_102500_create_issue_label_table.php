<?php

declare(strict_types=1);

use App\Domains\Issues\Models\Issue;
use App\Domains\Issues\Models\Label;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The labels on an issue.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('issue_label', function (Blueprint $table) {
            $table->foreignIdFor(Issue::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Label::class)->constrained()->cascadeOnDelete();

            $table->primary(['issue_id', 'label_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_label');
    }
};
