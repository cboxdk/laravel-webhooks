<?php

declare(strict_types=1);

use Cbox\Webhooks\Contracts\SecretEncrypter;
use Cbox\Webhooks\Models\Casts\SealedHeaders;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seal the header values 0.2 stored in plain text. Each value is sealed with
 * the bound SecretEncrypter and marked `sealed:`, so running this twice seals
 * nothing twice, and a row written in plain text after it ran (an old worker
 * still deploying) is still read correctly and sealed on its next save.
 *
 * Down opens the values again, so 0.2 can read them.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite(static fn (array $headers, SecretEncrypter $encrypter): array => SealedHeaders::seal(SealedHeaders::unseal($headers, $encrypter), $encrypter));
    }

    public function down(): void
    {
        $this->rewrite(static fn (array $headers, SecretEncrypter $encrypter): array => SealedHeaders::unseal($headers, $encrypter));
    }

    /**
     * @param  Closure(array<string, string>, SecretEncrypter): array<string, string>  $change
     */
    private function rewrite(Closure $change): void
    {
        $encrypter = app(SecretEncrypter::class);
        $table = DB::connection($this->getConnection())->table($this->prefix().'endpoints');

        $table->clone()->whereNotNull('headers')->orderBy('id')->select(['id', 'headers'])->chunk(200, function ($rows) use ($table, $change, $encrypter): void {
            foreach ($rows as $row) {
                $decoded = is_string($row->headers) ? json_decode($row->headers, true) : null;

                if (! is_array($decoded)) {
                    continue;
                }

                $headers = [];

                foreach ($decoded as $name => $value) {
                    if (is_string($name) && is_string($value)) {
                        $headers[$name] = $value;
                    }
                }

                $table->clone()->where('id', $row->id)->update([
                    'headers' => json_encode($change($headers, $encrypter), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
            }
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
