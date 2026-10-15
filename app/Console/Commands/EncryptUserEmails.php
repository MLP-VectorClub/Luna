<?php

namespace App\Console\Commands;

use App\Utils\UserEmailEncryption;
use Illuminate\Console\Command;

/** Encrypts the plain e-mail addresses of users and builds the hashes lookups go by; safe to run again at any time */
class EncryptUserEmails extends Command
{
    protected $signature = 'users:encrypt-emails {--dry-run : only count what would change} {--rehash : encrypt everything again and rebuild every hash (after a key change)}';

    protected $description = 'Encrypt the e-mail addresses of users with the app key and (re)build their lookup hashes';

    public function handle(): int
    {
        $result = UserEmailEncryption::run((bool) $this->option('dry-run'), (bool) $this->option('rehash'));
        $this->info(sprintf('%d address(es) %s, %d hash(es) %s', $result['encrypted'], $this->option('dry-run') ? 'to encrypt' : 'encrypted', $result['hashed'], $this->option('dry-run') ? 'to build' : 'built'));
        foreach ($result['conflicts'] as $conflict) {
            $this->warn("User {$conflict['id']} has an address that another account already has (apart from its case), so it is not findable by it");
        }

        return $result['conflicts'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
