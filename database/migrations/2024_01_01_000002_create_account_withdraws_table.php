<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class CreateAccountWithdrawsTable extends Migration
{
    public function up(): void
    {
        Schema::create('account_withdraw', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('method', 50);
            $table->decimal('amount', 15, 2);
            $table->boolean('scheduled')->default(false);
            $table->dateTime('scheduled_for')->nullable();
            $table->boolean('done')->default(false);
            $table->boolean('error')->default(false);
            $table->text('error_reason')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();
            
            $table->foreign('account_id')->references('id')->on('account')->onDelete('cascade');
            $table->index('account_id');
            $table->index(['scheduled', 'done', 'scheduled_for']);
            $table->index('scheduled_for');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_withdraw');
    }
}

