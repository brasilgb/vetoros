<?php

use App\Support\WebChatAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

test('chat hours use business days and the Sao Paulo timezone', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-19 12:00:00', 'America/Sao_Paulo'));
    expect(WebChatAvailability::isOpen('09:00-18:00'))->toBeTrue();

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-19 18:00:00', 'America/Sao_Paulo'));
    expect(WebChatAvailability::isOpen('09:00-18:00'))->toBeFalse();

    Carbon::setTestNow(CarbonImmutable::parse('2026-10-18 12:00:00', 'America/Sao_Paulo'));
    expect(WebChatAvailability::isOpen('09:00-18:00'))->toBeFalse();
});
