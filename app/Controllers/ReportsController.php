<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\ReportsService;
use DateTimeImmutable;

final class ReportsController
{
    public function __construct(private ReportsService $reportsService)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function summary(string $view, array $input): array
    {
        $granularity = $this->normalizeView($view);
        $referenceDate = $this->resolveReferenceDate($granularity, $input);
        $advancedFilters = $this->extractAdvancedFilters($input);
        $report = $this->reportsService->buildReport($granularity, $referenceDate, $advancedFilters);
        $report['filters'] = [
            'date' => $granularity === 'daily' ? $referenceDate->format('Y-m-d') : '',
            'month' => $granularity === 'monthly' ? $referenceDate->format('Y-m') : '',
            'year' => $granularity === 'yearly' ? $referenceDate->format('Y') : '',
            'payment' => (string) ($report['selected_filters']['payment'] ?? ''),
            'operator_id' => isset($report['selected_filters']['operator_id'])
                ? (string) $report['selected_filters']['operator_id']
                : '',
        ];

        return $report;
    }

    private function normalizeView(?string $view): string
    {
        return match (strtolower((string) $view)) {
            'day', 'giorno', 'daily' => 'daily',
            'month', 'mese', 'monthly' => 'monthly',
            'year', 'anno', 'yearly' => 'yearly',
            default => 'daily',
        };
    }

    /**
     * @param array<string, mixed> $input
     */
    private function resolveReferenceDate(string $granularity, array $input): DateTimeImmutable
    {
        $value = match ($granularity) {
            'monthly' => isset($input['month']) ? (string) $input['month'] : null,
            'yearly' => isset($input['year']) ? (string) $input['year'] : null,
            default => isset($input['date']) ? (string) $input['date'] : null,
        };

        return $this->parseReference($granularity, $value);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function extractAdvancedFilters(array $input): array
    {
        $filters = [];
        if (isset($input['payment'])) {
            $filters['payment'] = (string) $input['payment'];
        }
        if (isset($input['operator_id'])) {
            $filters['operator_id'] = $input['operator_id'];
        } elseif (isset($input['operator'])) {
            $filters['operator_id'] = $input['operator'];
        }

        return $filters;
    }

    private function parseReference(string $granularity, ?string $value): DateTimeImmutable
    {
        $fallback = (new DateTimeImmutable('today'))->setTime(0, 0, 0);
        if ($value === null) {
            return $fallback;
        }

        $clean = trim($value);
        if ($clean === '') {
            return $fallback;
        }

        try {
            return match ($granularity) {
                'monthly' => $this->parseMonthly($clean) ?? $fallback,
                'yearly' => $this->parseYearly($clean) ?? $fallback,
                default => $this->parseDaily($clean) ?? $fallback,
            };
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function parseDaily(string $value): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($parsed === false) {
            return null;
        }

        return $parsed->setTime(0, 0, 0);
    }

    private function parseMonthly(string $value): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m', $value);
        if ($parsed === false) {
            return null;
        }

        return $parsed->setDate((int) $parsed->format('Y'), (int) $parsed->format('m'), 1)->setTime(0, 0, 0);
    }

    private function parseYearly(string $value): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y', $value);
        if ($parsed === false) {
            return null;
        }

        return $parsed->setDate((int) $parsed->format('Y'), 1, 1)->setTime(0, 0, 0);
    }
}
