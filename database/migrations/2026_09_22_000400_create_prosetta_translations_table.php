<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Support\Settings;

/**
 * One row per key and locale. value/source_hash is the candidate under
 * review; approved_value/approved_source_hash is what users see. The two
 * hashes against the key's source_hash decide what is stale.
 * approved_source_value is the English the approved value was made from,
 * so an edit can be sent as a minimal update against it.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create(Settings::table('translations'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('key_id')->constrained(Settings::table('keys'))->cascadeOnDelete();
            $table->string('locale', 35);
            $table->text('value')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->text('approved_value')->nullable();
            $table->string('approved_source_hash', 64)->nullable();
            $table->text('approved_source_value')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('origin', 20)->default('manual');
            $table->json('issues')->nullable();
            $table->string('ai_provider')->nullable();
            $table->string('ai_model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('ai_invocation_id')->nullable();
            $table->string('exported_hash', 64)->nullable();
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['key_id', 'locale']);
            $table->index(['locale', 'status']);
        });
    }

    public function down(): void {
        Schema::dropIfExists(Settings::table('translations'));
    }
};
