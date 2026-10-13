<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateWebPushKeys extends Command
{
    protected $signature = 'webpush:keys';

    protected $description = 'Generate VAPID keys to add to the server environment';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->line('WEBPUSH_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('WEBPUSH_PRIVATE_KEY='.$keys['privateKey']);
        $this->comment('Keep the private key secret. Generate keys once; changing them requires devices to subscribe again.');

        return self::SUCCESS;
    }
}
