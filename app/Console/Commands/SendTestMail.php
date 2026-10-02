<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendTestMail extends Command
{
    protected $signature = 'mail:test {address : Where to send the test message}';

    protected $description = 'Send a test e-mail with the configured mail settings and print how it went';

    public function handle(): int
    {
        $address = $this->argument('address');
        $mailer = config('mail.default');
        $config = config("mail.mailers.$mailer");
        $this->line("Mailer: $mailer".(isset($config['host']) ? " ({$config['host']}:{$config['port']})" : '').', from '.config('mail.from.address'));

        try {
            Mail::raw('This is a test message from '.config('app.name').' ('.config('app.url').') sent at '.now()->toIso8601String().'. If you can read it, sending works.', function ($message) use ($address) {
                $message->to($address)->subject('Test message from '.config('app.name'));
            });
        } catch (Throwable $e) {
            $this->error('Sending failed: '.get_class($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Handed the message for $address to the mail server");

        return self::SUCCESS;
    }
}
