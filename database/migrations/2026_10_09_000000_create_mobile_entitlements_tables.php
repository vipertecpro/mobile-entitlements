<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('store', 16);
            $table->string('notification_id');
            $table->string('type');
            $table->string('subtype')->nullable();
            $table->string('original_transaction_id')->nullable()->index();
            $table->string('transaction_id')->nullable()->index();
            $table->text('purchase_token')->nullable();
            $table->string('purchase_token_hash', 64)->nullable()->index();
            $table->string('product_id')->nullable();
            $table->string('environment', 16)->nullable();
            $table->json('payload')->nullable();
            $table->longText('signed_payload')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['store', 'notification_id']);
        });

        Schema::create('entitlements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('store', 16);
            $table->string('product_id');
            $table->string('type', 32);
            $table->string('original_transaction_id')->index();
            $table->string('latest_transaction_id')->nullable();
            $table->string('purchase_token_hash', 64)->nullable()->index();
            $table->uuid('app_account_token')->nullable()->index();
            $table->boolean('is_active')->default(false);
            $table->timestamp('purchased_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('will_renew')->default(false);
            $table->boolean('in_grace_period')->default(false);
            $table->boolean('in_billing_retry')->default(false);
            $table->boolean('is_trial')->default(false);
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();
            $table->string('environment', 16)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('last_notification_type')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['store', 'original_transaction_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlements');
        Schema::dropIfExists('store_transactions');
    }
};
