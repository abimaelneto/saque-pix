<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

/**
 * @property string $id
 * @property string $name
 * @property string $balance
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Account extends Model
{
    protected ?string $table = 'account';

    protected string $primaryKey = 'id';

    public bool $incrementing = false;

    protected string $keyType = 'string';

    protected array $fillable = [
        'id',
        'name',
        'balance',
    ];

    protected array $casts = [
        'id' => 'string',
        'name' => 'string',
        'balance' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function withdraws()
    {
        return $this->hasMany(AccountWithdraw::class, 'account_id', 'id');
    }
}

