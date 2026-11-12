<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An event may name the person it is about, so a host can erase one person's
 * events inside a scope without erasing the scope.
 *
 * Additive: one nullable column and an index. Events published before this
 * have no subject and are only removed by a scope's erasure or by retention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->prefix().'events', function (Blueprint $table): void {
            $table->string('subject', 191)->nullable();
            $table->index(['partition', 'scope_key', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::table($this->prefix().'events', function (Blueprint $table): void {
            $table->dropIndex(['partition', 'scope_key', 'subject']);
        });

        Schema::table($this->prefix().'events', function (Blueprint $table): void {
            $table->dropColumn('subject');
        });
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
