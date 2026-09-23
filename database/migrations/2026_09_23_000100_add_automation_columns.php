<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Support\Settings;

/**
 * Background mode: per-language automation settings, the English each
 * approved translation was made from, and two durable tables (token usage,
 * and small state such as the cycle heartbeat). Guarded, so it runs cleanly
 * on hosts that own the locales table or migrate in a different order.
 */
return new class extends Migration {
    public function up(): void {
        $locales = Settings::table('locales');

        if (Schema::hasTable($locales) && ! Schema::hasColumn($locales, 'auto_translate')) {
            Schema::table($locales, function (Blueprint $table): void {
                $table->boolean('auto_translate')->default(false);
                $table->text('style_note')->nullable();
                $table->json('glossary')->nullable();
            });
        }

        $translations = Settings::table('translations');

        if (Schema::hasTable($translations) && ! Schema::hasColumn($translations, 'approved_source_value')) {
            Schema::table($translations, fn (Blueprint $table) => $table->text('approved_source_value')->nullable());
        }

        if (! Schema::hasTable(Settings::table('usage'))) {
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

        if (! Schema::hasTable(Settings::table('state'))) {
            Schema::create(Settings::table('state'), function (Blueprint $table): void {
                $table->string('key')->primary();
                $table->json('value')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void {
        Schema::dropIfExists(Settings::table('state'));
        Schema::dropIfExists(Settings::table('usage'));

        if (Schema::hasColumn(Settings::table('translations'), 'approved_source_value')) {
            Schema::table(Settings::table('translations'), fn (Blueprint $table) => $table->dropColumn('approved_source_value'));
        }

        if (Schema::hasColumn(Settings::table('locales'), 'auto_translate')) {
            Schema::table(Settings::table('locales'), fn (Blueprint $table) => $table->dropColumn(['auto_translate', 'style_note', 'glossary']));
        }
    }
};
