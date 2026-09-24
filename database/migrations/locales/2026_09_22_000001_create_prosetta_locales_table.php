<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Locales Prosetta knows. "active" means offered to members; "translated"
 * means Prosetta maintains the locale even when members cannot pick it.
 * Codes match lang folder names exactly and never change once created.
 * auto_translate, style_note and glossary drive background mode: which
 * languages the cycle drafts, and what every batch for them is told.
 * replacements makes a variant of the source language derived: its strings
 * come from the English by word replacement, with no AI.
 */
return new class extends Migration {
    public function up(): void {
        Schema::create(Settings::table('locales'), function (Blueprint $table): void {
            $table->id();
            $table->string('locale_initials', 35)->unique();
            $table->string('english_name');
            $table->string('native_name');
            $table->string('script')->nullable();
            $table->boolean('rtl')->default(false);
            $table->boolean('active')->default(false);
            $table->boolean('translated')->default(false);
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->boolean('auto_translate')->default(false);
            $table->text('style_note')->nullable();
            $table->json('glossary')->nullable();
            $table->json('replacements')->nullable();
            $table->timestamps();

            $table->index('active');
            $table->index('translated');
            $table->index('is_default');
        });
    }

    public function down(): void {
        Schema::dropIfExists(Settings::table('locales'));
    }
};
