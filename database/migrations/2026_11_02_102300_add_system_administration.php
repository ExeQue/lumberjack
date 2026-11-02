<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A system administrator sees and manages every team on this Lumberjack.
            $table->boolean('is_admin')->default(false)->after('email');
            $table->timestamp('suspended_at')->nullable()->after('is_admin');
        });

        Schema::create('admin_audit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('action', 48);
            $table->string('subject', 255)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });

        // Whoever runs this Lumberjack already: the first user is its administrator.
        $first = DB::table('users')->orderBy('id')->value('id');

        if ($first !== null) {
            DB::table('users')->where('id', $first)->update(['is_admin' => true]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_entries');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'suspended_at']);
        });
    }
};
