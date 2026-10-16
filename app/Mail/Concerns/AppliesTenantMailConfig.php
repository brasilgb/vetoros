<?php

namespace App\Mail\Concerns;

use App\Support\TenantMailConfig;

trait AppliesTenantMailConfig
{
    public function send($mailer)
    {
        return parent::send(TenantMailConfig::tenantMailer($this->tenantId));
    }
}
