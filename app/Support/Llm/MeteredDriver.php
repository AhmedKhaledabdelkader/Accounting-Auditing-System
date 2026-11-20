<?php

namespace App\Support\Llm;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Every AI call goes through here, whichever provider: refused before anything is sent when the user's monthly limit is used up
 * (a fatal ProviderException: the run, the PDF reading or the draft stops with the reason), and recorded once answered (AiBudget).
 * The check is before each call: calls already in flight when the limit is reached finish, so a month can end a few cents over.
 */
final class MeteredDriver implements ClassifierDriver
{
    public function __construct(private ClassifierDriver $inner) {}

    public function classify(ToolCall $call): PromiseInterface
    {
        $payer = AiBudget::payer();
        if ($call->warmUp === false && ($why = AiBudget::refusal($payer)) !== null) {
            return Create::rejectionFor(new ProviderException($why, true));
        }

        return $this->inner->classify($call)->then(function (ToolAnswer $answer) use ($payer, $call) {
            if ($answer->usage !== null) {
                AiBudget::record($payer, (string) ($call->tool['name'] ?? 'call'), $answer->usage);
            }

            return $answer;
        });
    }
}
