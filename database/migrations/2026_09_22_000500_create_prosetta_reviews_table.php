<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Support\Settings;

/** The audit trail. reviewer_id is a plain string so int and UUID users both fit; null means the system. */
return new class extends Migration {
    public function up(): void {
        Schema::create(Settings::table('reviews'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('translation_id')->constrained(Settings::table('translations'))->cascadeOnDelete();
            $table->string('reviewer_id')->nullable()->index();
            $table->string('action', 20);
            $table->text('previous_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists(Settings::table('reviews'));
    }
};
