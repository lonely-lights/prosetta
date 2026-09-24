<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Support\Settings;

/**
 * One row per successful provider call. Budgets sum from here, so a cache
 * clear can't reset spending; --estimate reads it for history.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create(Settings::table('usage'), function (Blueprint $table): void {
            $table->id();
            $table->string('run_id')->nullable()->index();
            $table->string('circuit');
            $table->string('locale', 35);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void {
        Schema::dropIfExists(Settings::table('usage'));
    }
};
