<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('brands', 'icon')) {
            Schema::table('brands', fn (Blueprint $table) => $table->string('icon')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('brands', fn (Blueprint $table) => $table->dropColumn('icon'));
    }
};
