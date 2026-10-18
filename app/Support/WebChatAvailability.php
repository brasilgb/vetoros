<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class WebChatAvailability
{
    /** Weekdays by default; an empty schedule means availability is not restricted. */
    public static function isOpen(?string $hours): bool
    {
        if (! $hours) {
            return true;
        }

        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/', $hours, $matches)) {
            return true;
        }

        $now = CarbonImmutable::now('America/Sao_Paulo');
        if ($now->isWeekend()) {
            return false;
        }

        $current = $now->format('H:i');
        $opens = $matches[1].':'.$matches[2];
        $closes = $matches[3].':'.$matches[4];

        return $current >= $opens && $current < $closes;
    }
}
