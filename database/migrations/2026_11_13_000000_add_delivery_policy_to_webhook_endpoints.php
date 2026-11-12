<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An endpoint's own delivery policy. Additive: every column is nullable or has
 * a default, and null means "the configured default", so every existing
 * endpoint keeps delivering exactly as before.
 *
 *  - `retry_schedule`: the seconds between attempts; `[]` is one try, no retries.
 *  - `timeout_seconds`: the request timeout.
 *  - `follow_redirects`: follow 307 and 308, each hop guarded and pinned.
 *  - `transform`: an expression for the host's PayloadTransformer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->prefix().'endpoints', function (Blueprint $table): void {
            $table->json('retry_schedule')->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->nullable();
            $table->boolean('follow_redirects')->default(false);
            $table->text('transform')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table($this->prefix().'endpoints', function (Blueprint $table): void {
            $table->dropColumn(['retry_schedule', 'timeout_seconds', 'follow_redirects', 'transform']);
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
