<?php

use App\Mail\Concerns\AppliesTenantMailConfig;
use App\Mail\MaintenanceInvoiceMail;
use App\Mail\OrderBudgetFollowUpMail;
use App\Mail\OrderCreatedMail;
use App\Mail\OrderFeedbackReminderMail;
use App\Mail\OrderPaymentReminderMail;
use App\Mail\OrderStatusUpdatedMail;
use App\Mail\SaasFiscalDocumentMail;
use App\Mail\SubscriptionInvoicePaidMail;
use App\Mail\SubscriptionStatusMail;
use App\Mail\TenantFeedbackRequestMail;
use App\Mail\TenantImprovementRequestCreatedMail;
use App\Mail\TenantImprovementRequestUpdatedMail;
use App\Mail\UserRegisteredMail;
use App\Models\User;
use App\Support\RequestCache;
use App\Support\TenantMailConfig;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\View\Factory;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class InstitutionalTransportProbe extends UserRegisteredMail
{
    public function content(): Content
    {
        return new Content(htmlString: '<p>Institutional</p>');
    }
}

class TenantTransportProbe extends Mailable
{
    use AppliesTenantMailConfig;

    public function __construct(public ?int $tenantId) {}

    public function content(): Content
    {
        return new Content(htmlString: '<p>Tenant</p>');
    }
}

beforeEach(function () {
    $this->mailApp = new Application(dirname(__DIR__, 2));
    $this->mailApp->instance('config', new Repository([
        'app' => ['name' => 'VetorOS'],
        'mail' => [
            'default' => 'smtp',
            'from' => ['address' => 'root@example.test', 'name' => 'RootAdmin'],
            'system' => [
                'mailer' => 'smtp', 'host' => 'root.example.test', 'port' => 465,
                'scheme' => 'smtps', 'username' => 'root-user', 'password' => 'root-secret',
                'from_address' => 'root@example.test', 'from_name' => 'RootAdmin',
            ],
            'mailers' => ['smtp' => ['transport' => 'smtp', 'host' => 'root.example.test', 'port' => 465]],
        ],
    ]));
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($this->mailApp);
    $this->mailApp->scoped(RequestCache::class);
    $this->mailApp->instance('events', new Dispatcher($this->mailApp));
    $this->mailApp->instance('encrypter', new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
    $this->mailApp->instance('view', Mockery::mock(Factory::class));
    Schema::swap(Mockery::mock());
    Schema::shouldReceive('hasTable')->with('others')->andReturnTrue()->byDefault();
    DB::swap(Mockery::mock());
    $this->mailApp->instance('mail.manager', new MailManager($this->mailApp));
    $this->rows = [];
    foreach ([1 => 'a', 2 => 'b'] as $id => $name) {
        $this->rows[$id] = (object) [
            'mail_mailer' => 'smtp', 'mail_host' => "$name.example.test",
            'mail_port' => 587, 'mail_encryption' => 'tls',
            'mail_username' => "$name@example.test", 'mail_password' => Crypt::encryptString("$name-secret"),
            'mail_from_address' => "$name@example.test", 'mail_from_name' => "Tenant $name",
        ];
    }
    DB::shouldReceive('table')->with('others')->andReturnUsing(function () {
        $query = Mockery::mock();
        $query->shouldReceive('where')->andReturnUsing(function ($column, $id) use ($query) {
            expect($column)->toBe('tenant_id');
            $query->shouldReceive('first')->andReturn($this->rows[$id] ?? null);

            return $query;
        });
        $query->shouldReceive('select')->andReturnSelf();
        $query->shouldReceive('orderByDesc')->with('id')->andReturnSelf();

        return $query;
    });
    $this->capture = function () {
        $this->deliveries = [];
        $this->mailApp['mail.manager']->extend('smtp', function ($config) {
            $transport = new ArrayTransport;
            $this->deliveries[] = [$config, $transport];

            return $transport;
        });
        // Resolve PendingMail's default ahead of tenant selection, reproducing MAIL-02.
        $this->mailApp['mail.manager']->mailer();
        $this->deliveries = [];
    };
    $this->message = fn ($id) => $id === 0
        ? new InstitutionalTransportProbe(new User)
        : new TenantTransportProbe($id);
});

afterEach(function () {
    Mockery::close();
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
    Container::setInstance(null);
});

test('fresh SMTP transports preserve TLS, credentials and global configuration', function ($port, $encryption, $tls) {
    $this->rows[1]->mail_port = $port;
    $this->rows[1]->mail_encryption = $encryption;
    config(['mail.mailers.smtp.url' => 'smtps://corporate.invalid:465']);
    $before = config('mail');
    $root = TenantMailConfig::systemMailer()->getSymfonyTransport();
    $tenant = TenantMailConfig::tenantMailer(1)->getSymfonyTransport();

    expect($root->getStream()->isTLS())->toBeTrue()
        ->and($root->getStream()->getHost())->toBe('root.example.test')
        ->and($tenant->getStream()->isTLS())->toBe($tls)
        ->and($tenant->getStream()->getHost())->toBe('a.example.test')
        ->and($tenant->getUsername())->toBe('a@example.test')
        ->and($tenant->getPassword())->toBe('a-secret')
        ->and(config('mail'))->toBe($before);
})->with([[587, 'tls', false], [465, 'ssl', true]]);

test('persistent process selects transport and sender for every message', function ($sequence, $queued) {
    ($this->capture)();
    $before = config('mail');
    foreach ($sequence as $id) {
        $mail = ($this->message)($id);
        if ($queued) {
            $mail->to('recipient@example.test');
            (new SendQueuedMailable($mail))->handle($this->mailApp['mail.manager']);
            // Same lifecycle boundary used by Laravel's persistent queue worker.
            $this->mailApp->forgetScopedInstances();
        } else {
            Mail::to('recipient@example.test')->send($mail);
        }
    }
    expect($this->deliveries)->toHaveCount(count($sequence));
    foreach ($this->deliveries as $index => [$config, $transport]) {
        $name = [0 => 'root', 1 => 'a', 2 => 'b'][$sequence[$index]];
        $message = $transport->messages()->sole()->getOriginalMessage();
        expect($config['host'])->toBe("$name.example.test")
            ->and($config['username'])->toBe($name === 'root' ? 'root-user' : "$name@example.test")
            ->and($config['password'])->toBe($name === 'root' ? 'root-secret' : "$name-secret")
            ->and($message->getFrom()[0]->getAddress())->toBe("$name@example.test");
    }
    expect(config('mail'))->toBe($before);
})->with([
    'RootAdmin → A → RootAdmin' => [[0, 1, 0]],
    'A → B → A' => [[1, 2, 1]],
    'A → RootAdmin → B' => [[1, 0, 2]],
])->with(['synchronous' => false, 'queue jobs' => true]);

test('missing tenant SMTP fails without fallback and subsequent jobs remain isolated', function ($missing) {
    ($this->capture)();
    if ($missing === 'table') {
        Schema::shouldReceive('hasTable')->with('others')->andReturnFalse();
    } elseif (in_array($missing, ['mail_host', 'mail_port', 'mail_username', 'mail_password'])) {
        $this->rows[1]->{$missing} = null;
    } elseif ($missing === 'corrupt') {
        $this->rows[1]->mail_password = 'invalid-ciphertext';
    } elseif ($missing === 'empty') {
        $this->rows[1]->mail_password = Crypt::encryptString('');
    }
    $id = match ($missing) {
        'id' => null, 'row' => 99, default => 1
    };
    $before = config('mail');
    expect(fn () => Mail::to('recipient@example.test')->send(new TenantTransportProbe($id)))
        ->toThrow(RuntimeException::class);
    expect($this->deliveries)->toBeEmpty()->and(config('mail'))->toBe($before);
    // A following institutional delivery must still work, even after decryption failure.
    Mail::to('recipient@example.test')->send(($this->message)(0));
    expect($this->deliveries[0][0]['host'])->toBe('root.example.test');
})->with(['id', 'row', 'table', 'mail_host', 'mail_port', 'mail_username', 'mail_password', 'corrupt', 'empty']);

test('exception during delivery cannot contaminate following tenant or institutional sends', function () {
    ($this->capture)();
    $before = config('mail');
    $failing = new TenantTransportProbe(1);
    $failing->withSymfonyMessage(fn () => throw new RuntimeException('Synthetic failure'));
    expect(fn () => (new SendQueuedMailable($failing))->handle($this->mailApp['mail.manager']))
        ->toThrow(RuntimeException::class, 'Synthetic failure');
    $this->mailApp->forgetScopedInstances();
    Mail::to('recipient@example.test')->send(($this->message)(0));
    Mail::to('recipient@example.test')->send(($this->message)(2));
    expect($this->deliveries[0][1]->messages())->toHaveCount(0)
        ->and($this->deliveries[1][0]['host'])->toBe('root.example.test')
        ->and($this->deliveries[2][0]['host'])->toBe('b.example.test')
        ->and(config('mail'))->toBe($before);
});

test('tenant without explicit sender uses its own login, never the institutional sender', function () {
    ($this->capture)();
    $this->rows[1]->mail_from_address = null;
    Mail::to('recipient@example.test')->send(new TenantTransportProbe(1));
    expect($this->deliveries[0][1]->messages()->sole()->getOriginalMessage()->getFrom()[0]->getAddress())
        ->toBe('a@example.test');
});

test('every application mailable routes through its isolated transport', function ($class, $tenant) {
    ($this->capture)();
    // Keep real delivery methods; isolate template/model access from this routing test.
    $mail = Mockery::mock($class)->makePartial();
    if ($tenant) {
        $mail->tenantId = 1;
    }
    $mail->shouldReceive('content')->andReturn(new Content(htmlString: '<p>Routing</p>'));
    $mail->shouldReceive('attachments')->andReturn([]);
    $mail->shouldReceive('envelope')->andReturn(new Envelope(subject: 'Routing'));
    Mail::to('recipient@example.test')->send($mail);
    [$config, $transport] = $this->deliveries[0];
    expect($this->deliveries)->toHaveCount(1)
        ->and($config['host'])->toBe($tenant ? 'a.example.test' : 'root.example.test')
        ->and($transport->messages()->sole()->getOriginalMessage()->getFrom()[0]->getAddress())
        ->toBe($tenant ? 'a@example.test' : 'root@example.test');
})->with([
    [UserRegisteredMail::class, false],
    [SubscriptionStatusMail::class, false],
    [SubscriptionInvoicePaidMail::class, false],
    [SaasFiscalDocumentMail::class, false],
    [TenantFeedbackRequestMail::class, false],
    [TenantImprovementRequestCreatedMail::class, false],
    [TenantImprovementRequestUpdatedMail::class, false],
    [OrderCreatedMail::class, true],
    [OrderStatusUpdatedMail::class, true],
    [OrderBudgetFollowUpMail::class, true],
    [OrderPaymentReminderMail::class, true],
    [OrderFeedbackReminderMail::class, true],
    [MaintenanceInvoiceMail::class, true],
]);
