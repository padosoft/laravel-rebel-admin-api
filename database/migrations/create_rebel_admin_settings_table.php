<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rebel_admin_settings', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id')->nullable();
            $table->string('key');
            $table->json('value')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'key'], 'rebel_admin_setting_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rebel_admin_settings');
    }
};
