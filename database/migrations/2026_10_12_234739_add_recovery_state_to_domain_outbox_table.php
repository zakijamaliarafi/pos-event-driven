<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('domain_outbox', function (Blueprint $table) {
            $table->unsignedBigInteger('attempts')->default(0)->change();
            $table->unsignedInteger('failed_cycles')->default(0);
            $table->uuid('active_delivery_id')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('quarantined_at')->nullable();
            $table->uuid('blocked_event_id')->nullable();
            $table->string('blocked_consumer', 120)->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->string('last_failed_consumer', 120)->nullable();
            $table->index(['processed_at', 'quarantined_at', 'next_attempt_at', 'dispatched_at'], 'domain_outbox_recovery_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domain_outbox', function (Blueprint $table) {
            $table->dropIndex('domain_outbox_recovery_index');
            $table->dropColumn([
                'failed_cycles', 'active_delivery_id', 'next_attempt_at', 'quarantined_at',
                'blocked_event_id', 'blocked_consumer', 'last_failed_at', 'last_failed_consumer',
            ]);
            // Keep the wider dispatch counter so rollback cannot truncate operational data.
        });
    }
};
