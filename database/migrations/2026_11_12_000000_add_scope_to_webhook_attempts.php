<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attempts carry their delivery's scope, so one owner's attempts can be listed
 * and erased by an index instead of through a join on the deliveries.
 *
 * Additive: two nullable columns and an index. Existing rows are filled from
 * their delivery in one statement; on a very large table run it off-peak. An
 * attempt whose delivery is already gone keeps null, and goes with the next
 * prune like any other.
 */
return new class extends Migration
{
    public function up(): void
    {
        $attempts = $this->prefix().'attempts';
        $deliveries = $this->prefix().'deliveries';

        Schema::table($attempts, function (Blueprint $table): void {
            $table->string('partition')->nullable();
            $table->string('scope_key')->nullable();
        });

        foreach (['partition', 'scope_key'] as $column) {
            DB::table($attempts)->update([
                $column => DB::table($deliveries)->select($column)->whereColumn($deliveries.'.id', $attempts.'.delivery_id')->limit(1),
            ]);
        }

        Schema::table($attempts, function (Blueprint $table): void {
            $table->index(['partition', 'scope_key', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::table($this->prefix().'attempts', function (Blueprint $table): void {
            $table->dropIndex(['partition', 'scope_key', 'started_at']);
        });

        Schema::table($this->prefix().'attempts', function (Blueprint $table): void {
            $table->dropColumn(['partition', 'scope_key']);
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
