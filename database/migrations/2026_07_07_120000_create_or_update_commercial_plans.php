<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->upsertPlans([
            [
                'name' => 'Mensal',
                'slug' => 'mensal',
                'value' => 49.90,
                'billing_months' => 1,
                'description' => 'Acesso completo ao VetorOS por 1 mês.',
            ],
            [
                'name' => 'Trimestral',
                'slug' => 'trimestral',
                'value' => 134.90,
                'billing_months' => 3,
                'description' => 'Acesso completo ao VetorOS por 3 meses.',
            ],
            [
                'name' => 'Semestral',
                'slug' => 'semestral',
                'value' => 239.90,
                'billing_months' => 6,
                'description' => 'Acesso completo ao VetorOS por 6 meses.',
            ],
        ]);
    }

    public function down(): void
    {
        $this->upsertPlans([
            [
                'name' => 'Mensal',
                'slug' => 'mensal',
                'value' => 79.90,
                'billing_months' => 1,
                'description' => 'Acesso completo ao VetorOS por 1 mês.',
            ],
            [
                'name' => 'Trimestral',
                'slug' => 'trimestral',
                'value' => 219.90,
                'billing_months' => 3,
                'description' => 'Acesso completo ao VetorOS por 3 meses.',
            ],
            [
                'name' => 'Semestral',
                'slug' => 'semestral',
                'value' => 399.90,
                'billing_months' => 6,
                'description' => 'Acesso completo ao VetorOS por 6 meses.',
            ],
        ]);
    }

    private function upsertPlans(array $plans): void
    {
        foreach ($plans as $plan) {
            $query = DB::table('plans')->where('slug', $plan['slug']);

            if ($query->exists()) {
                $query->update([...$plan, 'updated_at' => now()]);

                continue;
            }

            DB::table('plans')->insert([...$plan, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
};
