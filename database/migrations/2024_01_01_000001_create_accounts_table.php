<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateAccountsTable extends Migration
{
    public function up(): void
    {
        Schema::create('account', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->decimal('balance', 15, 2)->default(0.00);
            $table->timestamps();
            
            $table->index('id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account');
    }
}

