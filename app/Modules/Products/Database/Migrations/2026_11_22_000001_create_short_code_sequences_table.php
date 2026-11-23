<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_code_sequences', function (Blueprint $table): void {
            $table->string('prefix', 3)->primary();
            $table->unsignedBigInteger('last_number')->default(0);
        });

        DB::table('short_code_sequences')->insert([
            ['prefix' => 'CAT', 'last_number' => 0],
            ['prefix' => 'TAX', 'last_number' => 0],
            ['prefix' => 'DIS', 'last_number' => 0],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('short_code_sequences');
    }
};
