<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A notice can start showing later than it was written (`show_after`, NULL = at once) and can be meant for one of the two sites that share the
 * database during the cutover (`site`: all, celestia or winterchilla). Winterchilla's own migration adds the same columns, so this one checks first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notices', function (Blueprint $table) {
            if (!Schema::hasColumn('notices', 'show_after')) {
                $table->timestampTz('show_after', config('app.timestamp_precision'))->nullable();
            }
            if (!Schema::hasColumn('notices', 'site')) {
                $table->string('site', 16)->default('all');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notices', function (Blueprint $table) {
            $table->dropColumn(['show_after', 'site']);
        });
    }
};
