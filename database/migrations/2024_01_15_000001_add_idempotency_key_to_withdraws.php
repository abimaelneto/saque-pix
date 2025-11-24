<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class AddIdempotencyKeyToWithdraws extends Migration
{
    public function up(): void
    {
        Schema::table('account_withdraw', function (Blueprint $table) {
            // Adicionar coluna idempotency_key para garantir idempotência
            $table->string('idempotency_key', 255)->nullable()->unique()->after('id');
            $table->index('idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('account_withdraw', function (Blueprint $table) {
            $table->dropIndex(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
}

