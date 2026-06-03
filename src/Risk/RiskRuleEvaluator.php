<?php

declare(strict_types=1);

namespace Padosoft\Rebel\AdminApi\Risk;

use Padosoft\Rebel\AdminApi\Models\RiskRule;

/**
 * A pure, read-only evaluation of risk rules against a set of input signals. Used by the
 * `risk-rules/simulate` endpoint so an operator can see what a request WOULD trigger before
 * any rule is activated. It never mutates state and never performs a real authentication —
 * it only reports the decision the active rules would produce.
 */
final class RiskRuleEvaluator
{
    /**
     * @param  iterable<RiskRule>  $rules  the active rules to evaluate (already tenant-scoped)
     * @param  array<string, mixed>  $signals  input signals (new_device, amount, country, …)
     * @return array{
     *     decision: string,
     *     required_assurance: string|null,
     *     require_phishing_resistant: bool,
     *     allowed_drivers: list<string>,
     *     matched_rules: list<string>,
     *     reasons: list<string>
     * }
     */
    public function evaluate(iterable $rules, array $signals): array
    {
        $matched = [];
        $reasons = [];
        $requiredRank = 0;
        $requiredAssurance = null;
        $phishingResistant = false;
        $blocked = false;

        foreach ($rules as $rule) {
            if (! $this->matches($rule, $signals)) {
                continue;
            }

            $matched[] = $rule->key;
            $reasons[] = $this->reasonFor($rule);

            if ($rule->action === 'block') {
                $blocked = true;
            }

            if ($rule->phishing_resistant) {
                $phishingResistant = true;
            }

            $rank = $this->assuranceRank($rule->required_assurance);
            if ($rank > $requiredRank) {
                $requiredRank = $rank;
                $requiredAssurance = $rule->required_assurance;
            }
        }

        $decision = $this->decision($blocked, $matched, $requiredAssurance);

        return [
            'decision' => $decision,
            'required_assurance' => $requiredAssurance,
            'require_phishing_resistant' => $phishingResistant,
            'allowed_drivers' => $this->allowedDrivers($requiredAssurance, $phishingResistant),
            'matched_rules' => array_values(array_unique($matched)),
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function matches(RiskRule $rule, array $signals): bool
    {
        if (! array_key_exists($rule->signal, $signals)) {
            return false;
        }

        $actual = $signals[$rule->signal];
        $expected = $rule->value;

        return match ($rule->operator) {
            '>' => $this->toFloat($actual) > $this->toFloat($expected),
            '>=' => $this->toFloat($actual) >= $this->toFloat($expected),
            '<' => $this->toFloat($actual) < $this->toFloat($expected),
            '<=' => $this->toFloat($actual) <= $this->toFloat($expected),
            '!=' => $this->scalar($actual) !== $this->scalar($expected),
            'in' => in_array($this->scalar($actual), array_map('trim', explode(',', $expected)), true),
            default => $this->looseEquals($actual, $expected),
        };
    }

    /**
     * @param  list<string>  $matched
     */
    private function decision(bool $blocked, array $matched, ?string $requiredAssurance): string
    {
        if ($blocked) {
            return 'block';
        }

        if ($matched === []) {
            return 'allow';
        }

        return $requiredAssurance !== null ? 'require_step_up' : 'allow';
    }

    private function reasonFor(RiskRule $rule): string
    {
        if ($rule->operator === 'in') {
            return $rule->signal.' in ['.$rule->value.']';
        }

        return $rule->signal.$rule->operator.$rule->value;
    }

    /**
     * @return list<string>
     */
    private function allowedDrivers(?string $requiredAssurance, bool $phishingResistant): array
    {
        if ($phishingResistant) {
            return ['fortify_passkey_confirm'];
        }

        return match ($requiredAssurance) {
            'aal3' => ['fortify_passkey_confirm'],
            'aal2' => ['fortify_passkey_confirm', 'fortify_totp'],
            default => ['fortify_passkey_confirm', 'fortify_totp', 'email_otp'],
        };
    }

    private function assuranceRank(?string $assurance): int
    {
        return match ($assurance) {
            'aal3' => 3,
            'aal2' => 2,
            'aal1' => 1,
            default => 0,
        };
    }

    private function looseEquals(mixed $actual, mixed $expected): bool
    {
        $expectedString = $this->scalar($expected);

        if (is_bool($actual)) {
            return $actual === in_array(strtolower($expectedString), ['1', 'true', 'yes'], true);
        }

        if (is_int($actual) || is_float($actual)) {
            return is_numeric($expectedString) && $this->toFloat($actual) === $this->toFloat($expectedString);
        }

        return $this->scalar($actual) === $expectedString;
    }

    private function toFloat(mixed $value): float
    {
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
