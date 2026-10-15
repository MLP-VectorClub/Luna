<?php

use App\Utils\UserEmailEncryption;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-mail addresses are stored encrypted with the app key (App\Utils\EmailVault). The ciphertext is longer and different every time, so the column
 * becomes text and loses its unique constraint, and what lookups and uniqueness go by is `email_hash`, a keyed hash of the lowercase address.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function ($table) {
            $table->string('email_hash', 64)->nullable();
        });
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE text');

        UserEmailEncryption::run();

        DB::statement('CREATE UNIQUE INDEX users_email_hash_unique ON users (email_hash) WHERE email_hash IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_hash_unique');
        UserEmailEncryption::decryptAll();
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE varchar(255)');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email)');
        Schema::table('users', function ($table) {
            $table->dropColumn('email_hash');
        });
    }
};
