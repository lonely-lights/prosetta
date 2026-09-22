<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LonelyLights\Prosetta\Support\Settings;

/** One row per lang file group: namespace '*' is the root lang folder, group '*' is its JSON file. */
return new class extends Migration {
    public function up(): void {
        Schema::create(Settings::table('files'), function (Blueprint $table): void {
            $table->id();
            $table->string('namespace', 191);
            $table->string('group', 191);
            $table->string('format', 10);
            $table->timestamps();

            $table->unique(['namespace', 'group']);
        });
    }

    public function down(): void {
        Schema::dropIfExists(Settings::table('files'));
    }
};
