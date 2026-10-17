<?php

namespace Tests\Feature;

use App\Support\DatabaseSafety;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Travas contra exclusão acidental de banco (VETOR-STABILIZE-01). Tudo roda no sqlite em
 * memória; os cenários de outros ambientes e bancos mudam só a configuração avaliada, nunca
 * conectam a um banco real.
 */
class DatabaseSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DB::prohibitDestructiveCommands(false);

        parent::tearDown();
    }

    public function test_suite_runs_only_on_isolated_memory_database(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertFalse(app()->configurationIsCached());
        $this->assertStringContainsString('bootstrap/cache/testing/', app()->getCachedConfigPath());
        $this->assertTrue(DatabaseSafety::destructiveCommandsAllowed(app()));
    }

    public function test_test_environment_never_reads_operational_credentials(): void
    {
        $this->assertEmpty(config('services.spedy.owner_api_key'));
        $this->assertSame('array', config('mail.default'));
    }

    public function test_destructive_commands_are_blocked_outside_memory_database(): void
    {
        config(['database.default' => 'mysql', 'database.connections.mysql.database' => 'vetoros']);
        $this->assertFalse(DatabaseSafety::destructiveCommandsAllowed(app()));

        app()['env'] = 'local';
        config(['database.default' => 'sqlite']);
        $this->assertFalse(DatabaseSafety::destructiveCommandsAllowed(app()));

        // Liberação explícita vale só fora de produção.
        config(['database.allow_destructive_commands' => true]);
        $this->assertTrue(DatabaseSafety::destructiveCommandsAllowed(app()));

        app()['env'] = 'production';
        $this->assertFalse(DatabaseSafety::destructiveCommandsAllowed(app()));

        app()['env'] = 'testing';
    }

    public function test_prohibited_commands_refuse_without_touching_the_database(): void
    {
        $migrations = DB::table('migrations')->count();
        $this->assertGreaterThan(0, $migrations);
        DB::prohibitDestructiveCommands();

        $this->assertSame(FreshCommand::FAILURE, Artisan::call('migrate:fresh', ['--force' => true]));
        $this->assertSame(WipeCommand::FAILURE, Artisan::call('db:wipe', ['--force' => true]));
        $this->assertSame(1, Artisan::call('migrate:rollback', ['--force' => true]));

        $this->assertTrue(Schema::hasTable('tenants'));
        $this->assertSame($migrations, DB::table('migrations')->count());
    }
}
