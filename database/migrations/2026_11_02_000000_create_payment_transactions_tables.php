<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Online-payment evidence, kept apart from bookings.payment_method (what the Team Leader
 * collected) and bookings.preferred_payment_method (what the customer chose). A row here
 * never changes a booking's status; the dispatcher still confirms payment.
 *
 * Three separate identities: our reference, the provider's checkout session, and the
 * provider's payment — plus one row per webhook event for replay protection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('paymongo');
            $table->string('reference', 40)->unique();                 // our id, sent to the provider
            $table->string('payable_type', 10);                        // booking | group
            $table->unsignedBigInteger('payable_id');                  // the booking id, or the group's anchor booking id
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method', 20);                              // gcash (TowMate identifier)
            $table->unsignedBigInteger('amount_centavos');
            $table->string('currency', 3)->default('PHP');
            $table->json('payable_snapshot')->nullable();              // member booking ids + totals at session creation
            $table->string('status', 12)->default('pending');         // pending|paid|failed|cancelled|expired|review|duplicate
            $table->string('flag', 40)->nullable();                    // amount_mismatch, cash_conflict, superseded_total, ...
            $table->string('provider_checkout_session_id')->nullable()->unique();
            $table->string('provider_payment_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->boolean('livemode')->default(false);
            $table->string('last_event_id')->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['payable_type', 'payable_id']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('paymongo');
            $table->string('provider_event_id')->unique();             // evt_... — the replay guard
            $table->string('event_type', 80);
            $table->boolean('livemode')->default(false);
            $table->string('payload_hash', 64);                        // sha256 of the raw body; the body itself is not stored
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->string('outcome', 40);
            $table->timestamp('received_at');
            $table->timestamps();
        });

        // At most one open session and at most one verified payment per payable. Partial unique
        // indexes need PostgreSQL (what production and the test database use).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX payment_transactions_one_pending_per_payable ON payment_transactions (payable_type, payable_id) WHERE status = 'pending'");
            DB::statement("CREATE UNIQUE INDEX payment_transactions_one_paid_per_payable ON payment_transactions (payable_type, payable_id) WHERE status = 'paid'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_transactions');
    }
};
