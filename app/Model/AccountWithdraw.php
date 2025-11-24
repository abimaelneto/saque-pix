<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * @property string $id
 * @property ?string $idempotency_key
 * @property ?string $correlation_id
 * @property string $account_id
 * @property string $method
 * @property string $amount
 * @property bool $scheduled
 * @property ?\Carbon\Carbon $scheduled_for
 * @property bool $done
 * @property bool $error
 * @property ?string $error_reason
 * @property ?\Carbon\Carbon $processed_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class AccountWithdraw extends Model
{
    protected ?string $table = 'account_withdraw';

    protected string $primaryKey = 'id';

    public bool $incrementing = false;

    protected string $keyType = 'string';

    protected array $fillable = [
        'id',
        'idempotency_key',
        'correlation_id',
        'account_id',
        'method',
        'amount',
        'scheduled',
        'scheduled_for',
        'done',
        'error',
        'error_reason',
        'processed_at',
    ];

    protected array $casts = [
        'id' => 'string',
        'idempotency_key' => 'string',
        'correlation_id' => 'string',
        'account_id' => 'string',
        'method' => 'string',
        'amount' => 'decimal:2',
        'scheduled' => 'boolean',
        'scheduled_for' => 'datetime',
        'done' => 'boolean',
        'error' => 'boolean',
        'error_reason' => 'string',
        'processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    public function pix()
    {
        return $this->hasOne(AccountWithdrawPix::class, 'account_withdraw_id', 'id');
    }
}

