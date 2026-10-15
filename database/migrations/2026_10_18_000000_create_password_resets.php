<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Links that let somebody set a new password: one per user at a time, only the hash of the token is kept */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('luna_password_resets', function (Blueprint $table) {
            $table->integer('user_id')->primary();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('luna_password_resets');
    }
};
