<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_submissions', function (Blueprint $table) {
            $table->uuid('idempotency_key')->primary();
            $table->char('request_fingerprint', 64);
            // Keep the original actor identity even if staff are later removed.
            $table->unsignedBigInteger('cashier_id')->nullable();
            $table->uuid('device_id')->nullable();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_submissions');
    }
};
