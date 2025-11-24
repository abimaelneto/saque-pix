<?php

declare(strict_types=1);

use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

class AddCorrelationIdToWithdraws extends Migration
{
    public function up(): void
    {
        Schema::table('account_withdraw', function (Blueprint $table) {
            // Adicionar coluna correlation_id para rastreamento distribuído
            $table->string('correlation_id', 36)->nullable()->after('idempotency_key');
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::table('account_withdraw', function (Blueprint $table) {
            $table->dropIndex(['correlation_id']);
            $table->dropColumn('correlation_id');
        });
    }
}

