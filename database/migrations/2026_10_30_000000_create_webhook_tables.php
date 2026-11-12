<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The five tables the package owns. Additive from here on: a later change ships
 * as a new migration, never as an edit to this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = $this->prefix();

        Schema::create($prefix.'endpoints', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('partition')->nullable()->index();
            $table->string('scope_key')->nullable()->index();
            $table->text('url');
            $table->string('description')->nullable();
            $table->json('event_types');
            $table->string('signature_scheme', 32)->default('cbox');
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();
            $table->string('status', 16)->default('active');
            $table->text('disabled_reason')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->unsignedInteger('batch_size')->default(1);
            $table->unsignedInteger('batch_window_seconds')->default(0);
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->json('headers')->nullable();
            $table->string('api_version', 64)->nullable();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('trip_count')->default(0);
            $table->timestamp('circuit_opened_at')->nullable()->index();
            $table->timestamp('first_failure_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('last_response_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['partition', 'scope_key', 'status']);
        });

        Schema::create($prefix.'events', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('partition')->nullable();
            $table->string('scope_key')->nullable();
            $table->string('type')->index();
            $table->string('idempotency_key')->nullable();
            // sha256(partition|scope_key|idempotency_key): a composite unique index with
            // nullable members is DB-specific, a single nullable unique column is not.
            $table->string('dedup_hash', 64)->nullable()->unique();
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->index(['partition', 'scope_key', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
        });

        Schema::create($prefix.'deliveries', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('endpoint_id', 26)->index();
            $table->string('event_id', 26)->index();
            $table->string('partition')->nullable();
            $table->string('scope_key')->nullable();
            $table->string('type');
            $table->unsignedBigInteger('sequence');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempt')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->string('batch_id', 26)->nullable()->index();
            $table->unsignedSmallInteger('last_response_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('exhausted_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->unique(['endpoint_id', 'sequence']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['endpoint_id', 'status', 'created_at']);
            $table->index(['partition', 'scope_key', 'created_at']);
        });

        Schema::create($prefix.'attempts', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('delivery_id', 26)->index();
            $table->string('endpoint_id', 26)->index();
            $table->string('batch_id', 26)->nullable();
            $table->unsignedInteger('attempt_number');
            $table->timestamp('started_at');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('outcome', 24);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('response_excerpt', 1000)->nullable();
            $table->text('error')->nullable();
            $table->string('request_id')->nullable();
            $table->timestamp('created_at');

            $table->index(['endpoint_id', 'started_at']);
        });

        Schema::create($prefix.'inbound_deliveries', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('endpoint_name', 64);
            $table->string('delivery_id');
            $table->string('event_id')->nullable();
            $table->string('type');
            $table->json('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('status', 16)->default('received');
            $table->text('error')->nullable();

            $table->unique(['endpoint_name', 'delivery_id']);
            $table->index(['endpoint_name', 'received_at']);
        });
    }

    public function down(): void
    {
        $prefix = $this->prefix();

        foreach (['inbound_deliveries', 'attempts', 'deliveries', 'events', 'endpoints'] as $table) {
            Schema::dropIfExists($prefix.$table);
        }
    }

    /**
     * The connection the package's tables live on (`webhooks.connection`); null
     * is the application's default.
     */
    public function getConnection(): ?string
    {
        $connection = config('webhooks.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private function prefix(): string
    {
        $prefix = config('webhooks.table_prefix', 'webhook_');

        return is_string($prefix) ? $prefix : 'webhook_';
    }
};
