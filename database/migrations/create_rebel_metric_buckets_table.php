<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rebel_metric_buckets', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id')->nullable();
            $table->timestamp('bucket'); // truncated to the hour
            $table->string('event_type');
            $table->string('channel')->nullable();
            $table->unsignedInteger('count')->default(0);

            $table->unique(['tenant_id', 'bucket', 'event_type', 'channel'], 'rebel_metric_bucket_unique');
            $table->index(['bucket', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rebel_metric_buckets');
    }
};
