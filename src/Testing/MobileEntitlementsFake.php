<?php

namespace Vipertecpro\MobileEntitlements\Testing;

use PHPUnit\Framework\Assert;
use Vipertecpro\MobileEntitlements\Enums\Store;
use Vipertecpro\MobileEntitlements\Exceptions\VerificationException;
use Vipertecpro\MobileEntitlements\MobileEntitlementsManager;
use Vipertecpro\MobileEntitlements\Models\Entitlement;
use Vipertecpro\MobileEntitlements\Support\VerifiedPurchase;

/**
 * Swapped in by MobileEntitlements::fake(). Store verification returns the purchases you register;
 * everything else (writing rows, linking users, events, /sync) runs for real.
 */
class MobileEntitlementsFake extends MobileEntitlementsManager
{
    /** @var array<string, VerifiedPurchase> */
    private array $purchases = [];

    /** @var list<array{store: Store, token: string, productId: string|null}> */
    private array $verified = [];

    /**
     * @param  array<string, VerifiedPurchase>  $purchases
     */
    public function withPurchases(array $purchases): static
    {
        foreach ($purchases as $token => $purchase) {
            $this->withPurchase((string) $token, $purchase);
        }

        return $this;
    }

    public function withPurchase(string $token, VerifiedPurchase $purchase): static
    {
        $this->purchases[$token] = $purchase;

        return $this;
    }

    public function verify(Store $store, string $token, ?string $productId = null): VerifiedPurchase
    {
        $this->verified[] = ['store' => $store, 'token' => $token, 'productId' => $productId];

        $purchase = $this->purchases[$token] ?? null;

        if ($purchase === null || $purchase->store !== $store) {
            throw new VerificationException('MobileEntitlementsFake: unknown token.');
        }

        return $purchase;
    }

    public function reconcileEntitlement(Entitlement $entitlement): Entitlement
    {
        foreach ($this->purchases as $purchase) {
            if ($purchase->store === $entitlement->store
                && $purchase->originalTransactionId === $entitlement->original_transaction_id
                && $purchase->productId === $entitlement->product_id) {
                $fresh = $this->writer->apply($purchase, 'reconcile');
                $fresh->touch();

                return $fresh;
            }
        }

        return $this->writer->refreshFromClock($entitlement, 'reconcile');
    }

    public function assertVerified(string $token, ?Store $store = null): void
    {
        $matches = array_filter(
            $this->verified,
            fn (array $call): bool => $call['token'] === $token && ($store === null || $call['store'] === $store),
        );

        Assert::assertNotEmpty($matches, "Token [{$token}] was not verified.");
    }

    public function assertNothingVerified(): void
    {
        Assert::assertEmpty($this->verified, 'Tokens were verified unexpectedly.');
    }
}
