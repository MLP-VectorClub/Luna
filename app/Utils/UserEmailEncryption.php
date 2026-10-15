<?php

namespace App\Utils;

use Illuminate\Support\Facades\DB;

/**
 * Brings the e-mail addresses of all users to the state `User` leaves them in (encrypted, with their keyed hash), for the migration that introduced
 * that, for addresses somebody else wrote plain (the old site does) and for after a key has changed (`php artisan users:encrypt-emails --rehash`)
 */
class UserEmailEncryption
{
    /**
     * @param  bool  $rehash  also encrypt addresses that are already encrypted again (the app key is a new one, the old one is in APP_PREVIOUS_KEYS)
     *                        and rebuild every hash
     * @return array{encrypted: int, hashed: int, conflicts: list<array{id: int, email_hash: string}>}
     */
    public static function run(bool $dry_run = false, bool $rehash = false): array
    {
        $result = ['encrypted' => 0, 'hashed' => 0, 'conflicts' => []];

        DB::table('users')->whereNotNull('email')->where('email', '!=', '')->orderBy('id')->chunkById(200, function ($rows) use (&$result, $dry_run, $rehash) {
            foreach ($rows as $row) {
                $plain = EmailVault::decrypt($row->email);
                if ($plain === null) {
                    // Encrypted with a key that is not here, nothing to be done about that one
                    continue;
                }
                $update = [];
                if ($rehash || !EmailVault::isEncrypted($row->email)) {
                    $update['email'] = EmailVault::encrypt($plain);
                    $result['encrypted']++;
                }
                $hash = EmailVault::hash($plain);
                if ($row->email_hash !== $hash) {
                    // Two accounts with the same address (apart from its case): the first one keeps the index, the other stays findable by name only. Asked
                    // beforehand, a unique violation would abort the transaction the migration runs in
                    if (DB::table('users')->where('email_hash', $hash)->where('id', '!=', $row->id)->exists()) {
                        $result['conflicts'][] = ['id' => (int) $row->id, 'email_hash' => (string) $hash];
                    } else {
                        $update['email_hash'] = $hash;
                        $result['hashed']++;
                    }
                }
                if ($update !== [] && !$dry_run) {
                    DB::table('users')->where('id', $row->id)->update($update);
                }
            }
        });

        return $result;
    }

    /** The other way, for rolling the migration back: plain addresses again */
    public static function decryptAll(): int
    {
        $count = 0;
        DB::table('users')->whereNotNull('email')->orderBy('id')->chunkById(200, function ($rows) use (&$count) {
            foreach ($rows as $row) {
                $plain = EmailVault::decrypt($row->email);
                if ($plain !== null && $plain !== $row->email) {
                    DB::table('users')->where('id', $row->id)->update(['email' => $plain]);
                    $count++;
                }
            }
        });

        return $count;
    }
}
