<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rebel_risk_rules', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id')->nullable();
            $table->string('key');

            // The signal/condition the rule matches on, e.g. amount > 1000.
            $table->string('signal');
            $table->string('operator', 8);              // > | >= | < | <= | == | != | in
            $table->string('value');                    // stored as string, compared numerically/loosely

            // What the rule decides when matched.
            $table->string('action');                   // require_step_up | force_driver | block | allow
            $table->string('required_assurance', 8)->nullable();
            $table->boolean('phishing_resistant')->default(false);

            $table->string('status')->default('draft'); // active | draft
            $table->timestamps();

            $table->unique(['tenant_id', 'key'], 'rebel_risk_rule_unique');
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rebel_risk_rules');
    }
};
