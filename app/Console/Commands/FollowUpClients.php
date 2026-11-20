<?php

namespace App\Console\Commands;

use App\Services\Portal\FollowUp;
use Illuminate\Console\Command;

class FollowUpClients extends Command
{
    protected $signature = 'portal:follow-up';

    protected $description = 'Email again each client who has not answered the missing-documents email (only when PORTAL_MAIL_AUTO_REMIND is on)';

    public function handle(FollowUp $followUp): int
    {
        $this->info(config('portal.mail.auto_remind') ? $followUp->remind().' reminder(s) sent.' : 'Automatic reminders are off (PORTAL_MAIL_AUTO_REMIND).');

        return self::SUCCESS;
    }
}
