<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Order matters: the SystemSeeder builds the référentiel + criteria + brief
     * domain graph the grader needs; the stock User factory preserves the
     * default Laravel dev user that some facades expect during local dev.
     */
    public function run(): void
    {
        Model::unguarded(function (): void {
            (new SystemSeeder())->run();

            \App\Models\User::factory()->create([
                'name'  => 'Test User',
                'email' => 'test@example.com',
            ]);
        });
    }
}