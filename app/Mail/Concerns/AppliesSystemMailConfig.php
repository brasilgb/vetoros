<?php

namespace App\Mail\Concerns;

use App\Support\TenantMailConfig;

trait AppliesSystemMailConfig
{
    public function send($mailer)
    {
        return parent::send(TenantMailConfig::systemMailer());
    }
}
