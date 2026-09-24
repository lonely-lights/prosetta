<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Support\Settings;

/**
 * The canonical keys, read from the source locale's files. key_hash carries
 * uniqueness because JSON keys are whole sentences too long to index.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create(Settings::table('keys'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('file_id')->constrained(Settings::table('files'))->cascadeOnDelete();
            $table->string('kind', 20)->default('file');
            $table->text('key');
            $table->string('key_hash', 64);
            $table->text('source_value');
            $table->string('source_hash', 64);
            $table->json('placeholders')->nullable();
            $table->text('context')->nullable();
            $table->unsignedInteger('max_length')->nullable();
            $table->timestamp('obsolete_at')->nullable();
            $table->timestamps();

            $table->unique(['file_id', 'key_hash']);
            $table->index('obsolete_at');
        });
    }

    public function down(): void {
        Schema::dropIfExists(Settings::table('keys'));
    }
};
