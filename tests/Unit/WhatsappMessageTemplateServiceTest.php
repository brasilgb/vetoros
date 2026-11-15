<?php

namespace Tests\Unit;

use App\Services\WhatsappMessageTemplateService;
use PHPUnit\Framework\TestCase;

class WhatsappMessageTemplateServiceTest extends TestCase
{
    public function test_defaults_do_not_advertise_removed_order_portal(): void
    {
        foreach ((new WhatsappMessageTemplateService)->defaultMessages() as $message) {
            $this->assertStringNotContainsString('link_os', $message);
            $this->assertStringNotContainsString('/os/', $message);
        }
    }

    public function test_saved_legacy_link_line_is_removed_when_rendering(): void
    {
        $template = "Olá, {{ cliente }}!\nAcompanhe pelo link: {{ link_os }}\nResponda para conversar.";
        $result = (new WhatsappMessageTemplateService)->render($template, ['cliente' => 'Ana', 'link_os' => 'https://example.test/os/token']);

        $this->assertSame("Olá, Ana!\nResponda para conversar.", $result);
    }
}
