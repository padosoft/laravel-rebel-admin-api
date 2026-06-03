<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Padosoft\Rebel\AdminApi\Models\RiskRule;
use Padosoft\Rebel\AdminApi\Risk\RiskRuleEvaluator;
use Padosoft\Rebel\AdminApi\Support\AdminAudit;

/**
 * Risk rule read model, draft editor and simulator for §3.7.
 *
 * `index` lists the tenant's rules; `store` PERSISTS a rule (created as a DRAFT by default —
 * never auto-applied); `simulate` is a pure, read-only evaluation of the ACTIVE rules against
 * input signals so an operator can preview the decision before activating anything.
 */
final class RiskRulesController
{
    public function __construct(private readonly AdminAudit $audit) {}

    /** GET {prefix}/risk-rules */
    public function index(Request $request): JsonResponse
    {
        $query = RiskRule::query()->withoutGlobalScopes()->orderBy('key');

        $tenant = $this->tenant($request);
        if ($tenant !== null) {
            $query->where('tenant_id', $tenant);
        }

        $rules = $query->get()->map(fn (RiskRule $rule): array => $this->present($rule))->all();

        return response()->json(['rules' => $rules]);
    }

    /** POST {prefix}/risk-rules — create or update a draft rule (idempotent on key+tenant). */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'key' => ['required', 'string', 'max:191'],
                'signal' => ['required', 'string', 'max:191'],
                'operator' => ['required', 'string', 'in:>,>=,<,<=,==,!=,in'],
                'value' => ['required'],
                'action' => ['required', 'string', 'in:require_step_up,force_driver,block,allow'],
                'required_assurance' => ['nullable', 'string', 'in:aal1,aal2,aal3'],
                'phishing_resistant' => ['sometimes', 'boolean'],
                'status' => ['sometimes', 'string', 'in:active,draft'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'validation_failed', 'messages' => $e->errors()], 422);
        }

        $tenant = $this->tenant($request);
        $key = $request->string('key')->toString();
        $assurance = $request->string('required_assurance')->toString();

        /** @var RiskRule $rule */
        $rule = RiskRule::query()->withoutGlobalScopes()->firstOrNew([
            'tenant_id' => $tenant,
            'key' => $key,
        ]);

        $rule->fill([
            'tenant_id' => $tenant,
            'key' => $key,
            'signal' => $request->string('signal')->toString(),
            'operator' => $request->string('operator')->toString(),
            'value' => $this->scalar($request->input('value')),
            'action' => $request->string('action')->toString(),
            'required_assurance' => $assurance === '' ? null : $assurance,
            'phishing_resistant' => $request->boolean('phishing_resistant'),
            // Default to DRAFT: a panel save never silently activates a rule.
            'status' => $request->has('status') ? $request->string('status')->toString() : 'draft',
        ]);
        $rule->save();

        $this->audit->record('risk_rule.saved', Auth::user(), $tenant, [
            'key' => $rule->key,
            'status' => $rule->status,
        ]);

        return response()->json(['rule' => $this->present($rule)], $rule->wasRecentlyCreated ? 201 : 200);
    }

    /** POST {prefix}/risk-rules/simulate — pure, read-only evaluation over input signals. */
    public function simulate(Request $request, RiskRuleEvaluator $evaluator): JsonResponse
    {
        $signals = $request->input('signals');
        if (! is_array($signals)) {
            return response()->json(['error' => 'invalid_signals'], 422);
        }

        /** @var array<string, mixed> $signals */
        $query = RiskRule::query()->withoutGlobalScopes()->where('status', 'active');

        $tenant = $this->tenant($request);
        if ($tenant !== null) {
            $query->where('tenant_id', $tenant);
        }

        /** @var iterable<RiskRule> $rules */
        $rules = $query->get();

        return response()->json($evaluator->evaluate($rules, $signals));
    }

    /**
     * @return array{key: string, signal: string, operator: string, value: int|float|string, action: string, required_assurance: string|null, phishing_resistant: bool, status: string}
     */
    private function present(RiskRule $rule): array
    {
        return [
            'key' => $rule->key,
            'signal' => $rule->signal,
            'operator' => $rule->operator,
            'value' => $this->normalizeValue($rule->value),
            'action' => $rule->action,
            'required_assurance' => $rule->required_assurance,
            'phishing_resistant' => $rule->phishing_resistant,
            'status' => $rule->status,
        ];
    }

    /** Present a numeric stored value as a number so the JSON contract matches the spec. */
    private function normalizeValue(string $value): int|float|string
    {
        if (! is_numeric($value)) {
            return $value;
        }

        return str_contains($value, '.') ? (float) $value : (int) $value;
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function tenant(Request $request): ?string
    {
        $tenant = $request->string('tenant')->toString();

        return $tenant === '' ? null : $tenant;
    }
}
