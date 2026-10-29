<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per fetch of Anthropic's server-side quota figures.
 *
 * Why store at all, when the endpoint is always one click away: `/usage` and
 * `/stats` are both point-in-time. Persisting every reading — including the
 * failed ones — is the only way to ever have a quota *history*, which neither
 * of those can give. See workspace/26-Cost-Quota/07-server-quota.md.
 *
 * `payload` keeps the response verbatim because the shape is undocumented and
 * carries feature-flagged buckets we deliberately don't parse yet; storing it
 * whole means a future bucket type is recoverable from old rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quota_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestampTz('fetched_at')->index();
            $table->string('status');
            $table->string('account')->nullable();
            $table->text('reason')->nullable();

            // Denormalised from payload.limits[] so the common read is a column
            // scan, not a jsonb dig. Null whenever the fetch failed.
            $table->unsignedSmallInteger('five_hour_percent')->nullable();
            $table->unsignedSmallInteger('seven_day_percent')->nullable();
            $table->timestampTz('five_hour_resets_at')->nullable();
            $table->timestampTz('seven_day_resets_at')->nullable();

            $table->jsonb('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quota_snapshots');
    }
};
