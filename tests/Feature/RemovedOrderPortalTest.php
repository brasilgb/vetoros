<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RemovedOrderPortalTest extends TestCase
{
    public function test_order_portal_and_token_document_routes_are_removed(): void
    {
        foreach ([
            'os.token', 'os.access', 'orders.budget.status',
            'orders.notification.acknowledge', 'orders.pickup.acknowledge',
            'os.feedback.submit', 'os.receipt', 'os.payment-proof',
            'os.fiscal-proof', 'os.fiscal-proof.pdf',
        ] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }

        $this->get('/os/obsolete-token')->assertNotFound();
        $this->get('/os/obsolete-token/receipt/orentrega')->assertNotFound();
        $this->get('/os/obsolete-token/payment-proof')->assertNotFound();
        $this->get('/os/obsolete-token/fiscal-proof')->assertNotFound();
    }
}
