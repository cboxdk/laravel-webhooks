<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The envelope format an endpoint is sent in. Additive: one column with a
 * default, so every existing endpoint keeps the `cbox` envelope it had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->prefix().'endpoints', function (Blueprint $table): void {
            $table->string('envelope', 32)->default('cbox');
        });
    }

    public function down(): void
    {
        Schema::table($this->prefix().'endpoints', function (Blueprint $table): void {
            $table->dropColumn('envelope');
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
