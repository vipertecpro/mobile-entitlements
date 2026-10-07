<?php

namespace Vipertecpro\MobileEntitlements\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Vipertecpro\MobileEntitlements\Enums\Store;

/**
 * One received store notification or one /sync verification. Idempotency key: (store, notification_id).
 *
 * @property int $id
 * @property Store $store
 * @property string $notification_id
 * @property string $type
 * @property string|null $subtype
 * @property string|null $original_transaction_id
 * @property string|null $transaction_id
 * @property string|null $purchase_token
 * @property string|null $purchase_token_hash
 * @property string|null $product_id
 * @property string|null $environment
 * @property array<string, mixed>|null $payload
 * @property string|null $signed_payload
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property string|null $error
 */
class StoreTransaction extends Model
{
    protected $table = 'store_transactions';

    protected $guarded = ['id'];

    /** @var list<string> */
    protected $hidden = ['purchase_token', 'signed_payload'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'store' => Store::class,
            'payload' => 'array',
            'purchase_token' => 'encrypted',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
