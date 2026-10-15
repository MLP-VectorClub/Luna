<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Second factors of an account. New tables of Luna's own, `users` is shared with Winterchilla and stays as it is
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('luna_two_factor', function (Blueprint $table) {
            $table->integer('user_id')->primary();
            // The TOTP secret, encrypted with the app key
            $table->text('secret');
            $table->timestampTz('confirmed_at')->nullable();
            // The 30 second step of the last accepted code, a code is only good once
            $table->bigInteger('last_used_step')->nullable();
            $table->timestampsTz();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()->cascadeOnUpdate();
        });

        Schema::create('luna_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->index();
            $table->string('code_hash', 64);
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at')->nullable()->useCurrent();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()->cascadeOnUpdate();
        });

        Schema::create('luna_passkeys', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id')->index();
            // base64url of the credential id, what the browser reports back
            $table->string('credential_id', 1024)->unique();
            // The serialized credential record (public key, counter, transports, flags)
            $table->text('record');
            $table->string('name', 64);
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('created_at')->nullable()->useCurrent();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('luna_passkeys');
        Schema::dropIfExists('luna_recovery_codes');
        Schema::dropIfExists('luna_two_factor');
    }
};
