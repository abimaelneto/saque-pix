<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateAccountWithdrawPixTable extends Migration
{
    public function up(): void
    {
        Schema::create('account_withdraw_pix', function (Blueprint $table) {
            $table->uuid('account_withdraw_id')->primary();
            $table->string('type', 50);
            $table->string('key', 255);
            $table->timestamps();
            
            $table->foreign('account_withdraw_id')
                ->references('id')
                ->on('account_withdraw')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_withdraw_pix');
    }
}

