<?php

use App\Support\TenantMailConfig;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->mailApp = new Application(dirname(__DIR__, 2));
    $this->mailApp->instance('config', new Repository([
        'mail' => [
            'default' => 'smtp',
            'system' => [
                'mailer' => 'smtp', 'host' => 'smtp.hostinger.com', 'port' => 465,
                'scheme' => 'smtps', 'from_address' => 'contato@abrasilsistemas.com.br',
                'from_name' => 'VetorOS - ABrasil Sistemas',
            ],
            'mailers' => ['smtp' => ['transport' => 'smtp']],
        ],
    ]));
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->mailApp);
    $this->mailApp->instance('view', Mockery::mock(Illuminate\Contracts\View\Factory::class));
    Schema::swap(Mockery::mock());
    DB::swap(Mockery::mock());
    $this->mailApp->instance('mail.manager', new MailManager($this->mailApp));
});

afterEach(function () {
    Mockery::close();
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
    Illuminate\Container\Container::setInstance(null);
});

test('corporate transport uses implicit TLS and restores its sender after a tenant', function () {
    config(['mail.mailers.smtp.scheme' => 'smtp', 'mail.mailers.smtp.url' => 'smtp://old.invalid:25']);
    TenantMailConfig::applySystemDefault();
    $transport = $this->mailApp['mail.manager']->mailer()->getSymfonyTransport();

    expect($transport->getStream()->isTLS())->toBeTrue()
        ->and($transport->getStream()->getHost())->toBe('smtp.hostinger.com')
        ->and(config('mail.mailers.smtp.url'))->toBeNull()
        ->and(config('mail.from.address'))->toBe('contato@abrasilsistemas.com.br');
});

test('tenant transport does not inherit corporate TLS scheme or URL', function ($port, $encryption, $tls) {
    TenantMailConfig::applySystemDefault();
    config(['mail.mailers.smtp.url' => 'smtps://corporate.invalid:465']);
    Schema::shouldReceive('hasTable')->with('others')->andReturnTrue();
    $query = Mockery::mock();
    DB::shouldReceive('table')->with('others')->andReturn($query);
    $query->shouldReceive('where')->with('tenant_id', 42)->andReturnSelf();
    $query->shouldReceive('select')->andReturnSelf();
    $query->shouldReceive('orderByDesc')->with('id')->andReturnSelf();
    $query->shouldReceive('first')->andReturn((object) [
        'mail_mailer' => 'smtp', 'mail_host' => 'tenant.example.test',
        'mail_port' => $port, 'mail_encryption' => $encryption,
        'mail_username' => 'tenant-user', 'mail_password' => null,
        'mail_from_address' => 'tenant@example.test', 'mail_from_name' => 'Tenant',
    ]);

    TenantMailConfig::applyForTenantId(42);
    $transport = $this->mailApp['mail.manager']->mailer()->getSymfonyTransport();

    expect($transport->getStream()->isTLS())->toBe($tls)
        ->and($transport->getStream()->getHost())->toBe('tenant.example.test')
        ->and(config('mail.mailers.smtp.url'))->toBeNull()
        ->and(config('mail.from.address'))->toBe('tenant@example.test');

    TenantMailConfig::applySystemDefault();
    expect($this->mailApp['mail.manager']->mailer()->getSymfonyTransport()->getStream()->isTLS())->toBeTrue()
        ->and(config('mail.from.address'))->toBe('contato@abrasilsistemas.com.br');
})->with([[587, 'tls', false], [465, 'ssl', true]]);
