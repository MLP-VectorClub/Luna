<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum 3 and later write this column whenever a token is created, without it every sign-in fails
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestampTz('expires_at', config('app.timestamp_precision'))->nullable()->index();
        });
    }

    public function down()
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
