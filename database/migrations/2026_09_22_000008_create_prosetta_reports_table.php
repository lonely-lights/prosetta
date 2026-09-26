<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Members' reports of a bad translation. key_id is null while the reported
 * words can't be traced to one string; reporter_id and resolved_by are
 * plain strings so int and UUID users both fit.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create(Settings::table('reports'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('key_id')->nullable()->constrained(Settings::table('keys'))->cascadeOnDelete();
            $table->string('locale', 35);
            $table->text('selected_text');
            $table->text('suggestion')->nullable();
            $table->text('notes')->nullable();
            $table->string('reporter_id')->index();
            $table->string('url', 2048)->nullable();
            $table->string('status', 20)->default('open');
            $table->string('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'locale']);
            $table->index(['key_id', 'locale', 'status']);
        });
    }

    public function down(): void {
        Schema::dropIfExists(Settings::table('reports'));
    }
};
