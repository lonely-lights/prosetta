<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Small durable state a cache clear must not lose: the cycle heartbeat,
 * the running cycle's batch, and the per-key failure counts.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create(Settings::table('state'), function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void {
        Schema::dropIfExists(Settings::table('state'));
    }
};
