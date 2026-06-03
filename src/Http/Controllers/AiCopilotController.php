<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Http\Controllers;

use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Padosoft\Rebel\AiGuard\AiExplainer;
use Padosoft\Rebel\AiGuard\Models\AnomalyCase;

/**
 * AI Security Copilot endpoints for §3.9 — the AI EXPLAINS, it never decides.
 *
 * `explain` asks ai-guard's AiExplainer (if an AiClient is bound) to narrate an anomaly case;
 * `suggest` proposes a DRAFT rule the operator may save. When no AI provider is configured a
 * clear, deterministic fallback is returned so the panel always has something to show — and
 * suggestions are always drafts that are never auto-applied.
 */
final class AiCopilotController
{
    public function __construct(private readonly Container $container) {}

    /** POST {prefix}/ai/anomalies/{case}/explain */
    public function explain(Request $request, string $case): JsonResponse
    {
        if (! Schema::hasTable('rebel_anomaly_cases')) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $query = DB::table('rebel_anomaly_cases')->where('id', $case);
        $tenant = $request->string('tenant')->toString();
        if ($tenant !== '') {
            $query->where('tenant_id', $tenant);
        }

        $row = $query->first();
        if ($row === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $data = (array) $row;
        $explanation = $this->aiExplain($case);

        if ($explanation !== null) {
            return response()->json([
                'explanation' => $explanation,
                'confidence' => 'medium',
                'sources' => ['ai-guard'],
            ]);
        }

        // Deterministic fallback when no AiClient is bound.
        return response()->json([
            'explanation' => $this->fallbackExplanation($data),
            'confidence' => 'low',
            'sources' => ['rule-engine'],
        ]);
    }

    /** POST {prefix}/ai/policies/suggest */
    public function suggest(Request $request): JsonResponse
    {
        $signals = $request->input('signals');
        $signals = is_array($signals) ? $signals : [];

        $draft = [
            'key' => 'ai_suggested_'.substr(md5((string) json_encode($signals)), 0, 8),
            'signal' => 'risk_score',
            'operator' => '>=',
            'value' => 60,
            'action' => 'require_step_up',
            'required_assurance' => 'aal2',
            'phishing_resistant' => false,
            'status' => 'draft',
        ];

        return response()->json([
            'draft_rule' => $draft,
            'rationale' => 'Elevated risk signals suggest requiring an AAL2 step-up. Review and save as a draft before activation.',
        ]);
    }

    private function aiExplain(string $case): ?string
    {
        // ai-guard is an OPTIONAL package: only call into it when it is actually installed
        // and an AiExplainer is bound (i.e. an AiClient is configured). Otherwise the caller
        // falls back to a deterministic explanation.
        if (! class_exists(AiExplainer::class) || ! class_exists(AnomalyCase::class) || ! $this->container->bound(AiExplainer::class)) {
            return null;
        }

        $model = AnomalyCase::query()->withoutGlobalScopes()->whereKey($case)->first();
        if (! $model instanceof AnomalyCase) {
            return null;
        }

        $explainer = $this->container->make(AiExplainer::class);

        return $explainer->explain($model);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function fallbackExplanation(array $data): string
    {
        $type = is_string($data['type'] ?? null) ? $data['type'] : 'unknown';
        $severity = is_string($data['severity'] ?? null) ? $data['severity'] : 'unknown';
        $events = is_numeric($data['events_count'] ?? null) ? (int) $data['events_count'] : 0;

        return sprintf(
            'No AI provider is configured. Deterministic summary: a "%s" anomaly of %s severity was opened, correlating %d event(s). Review the case signals and apply a mitigation only after human review.',
            $type,
            $severity,
            $events,
        );
    }
}
