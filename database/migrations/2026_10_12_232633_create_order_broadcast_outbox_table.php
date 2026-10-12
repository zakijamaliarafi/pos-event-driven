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
        Schema::create('order_broadcast_outbox', function (Blueprint $table) {
            $table->bigIncrements('sequence');
            $table->uuid('id')->unique();
            $table->uuid('source_event_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('projection_version');
            $table->string('broadcast_connection', 50);
            $table->json('channels');
            $table->json('payload');
            $table->timestamp('created_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->unique(['order_id', 'projection_version'], 'order_broadcast_projection_unique');
            $table->index(['sent_at', 'dispatched_at'], 'order_broadcast_pending_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_broadcast_outbox');
    }
};
