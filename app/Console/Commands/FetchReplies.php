<?php

namespace App\Console\Commands;

use App\Services\Portal\ReplyFetcher;
use Illuminate\Console\Command;

class FetchReplies extends Command
{
    protected $signature = 'portal:fetch-replies';

    protected $description = 'Read the accountants\' mailboxes (IMAP) for the clients\' replies to the follow-up emails and let the AI handle them';

    public function handle(ReplyFetcher $fetcher): int
    {
        $this->info($fetcher->runAll().' reply(ies) taken.');

        return self::SUCCESS;
    }
}
