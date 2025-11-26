<?php

declare(strict_types=1);

namespace App\Model;

use App\Service\EncryptionService;
use Hyperf\DbConnection\Model\Model;

/**
 * @property string $account_withdraw_id
 * @property string $type
 * @property string $key
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class AccountWithdrawPix extends Model
{
    protected ?string $table = 'account_withdraw_pix';

    protected string $primaryKey = 'account_withdraw_id';

    public bool $incrementing = false;

    protected string $keyType = 'string';

    protected array $fillable = [
        'account_withdraw_id',
        'type',
        'key',
    ];

    protected array $casts = [
        'account_withdraw_id' => 'string',
        'type' => 'string',
        'key' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Criptografa a chave PIX antes de salvar
     */
    public function setKeyAttribute(?string $value): void
    {
        if ($value === null) {
            $this->attributes['key'] = null;
            return;
        }

        // Verificar se já está criptografado (para evitar dupla criptografia)
        if ($this->isEncrypted($value)) {
            $this->attributes['key'] = $value;
            return;
        }

        // Criptografar antes de salvar
        $encryptionService = \Hyperf\Context\ApplicationContext::getContainer()
            ->get(EncryptionService::class);
        
        $this->attributes['key'] = $encryptionService->encrypt($value);
    }

    /**
     * Descriptografa a chave PIX ao acessar
     */
    public function getKeyAttribute(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Se não estiver criptografado, retornar como está (compatibilidade com dados antigos)
        if (!$this->isEncrypted($value)) {
            return $value;
        }

        // Descriptografar ao acessar
        try {
            $encryptionService = \Hyperf\Context\ApplicationContext::getContainer()
                ->get(EncryptionService::class);
            
            return $encryptionService->decrypt($value);
        } catch (\Exception $e) {
            // Se falhar a descriptografia, logar erro mas retornar valor original
            \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Psr\Log\LoggerInterface::class)
                ->error('Failed to decrypt PIX key', [
                    'error' => $e->getMessage(),
                    'account_withdraw_id' => $this->account_withdraw_id,
                ]);
            
            return $value; // Retornar valor criptografado se descriptografia falhar
        }
    }

    /**
     * Verifica se um valor está criptografado
     */
    private function isEncrypted(string $value): bool
    {
        // Valores criptografados são base64 e têm tamanho mínimo
        if (strlen($value) < 20) {
            return false;
        }

        // Tentar decodificar base64
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }

        // Verificar se tem tamanho suficiente para IV + tag + dados
        return strlen($decoded) >= 12 + 16 + 1; // IV (12) + TAG (16) + dados (1+)
    }

    public function withdraw()
    {
        return $this->belongsTo(AccountWithdraw::class, 'account_withdraw_id', 'id');
    }
}

