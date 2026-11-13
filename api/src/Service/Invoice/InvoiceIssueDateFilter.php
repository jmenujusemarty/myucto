<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Http\IsoDate;

/** Výslovný integrační rozsah vystavení, nezávislý na účetním období DUZP. */
final class InvoiceIssueDateFilter
{
    public static function parse(array $filter): array
    {
        $result = [];
        foreach (['issue_date_from', 'issue_date_to'] as $key) {
            if (!array_key_exists($key, $filter)) continue;
            $value = $filter[$key];
            if (!is_string($value) || !IsoDate::isValid($value)) {
                throw new \InvalidArgumentException("filter[$key] musí být datum YYYY-MM-DD.");
            }
            $result[$key] = $value;
        }
        if (isset($result['issue_date_from'], $result['issue_date_to'])
            && $result['issue_date_from'] > $result['issue_date_to']) {
            throw new \InvalidArgumentException('filter[issue_date_from] nesmí být po issue_date_to.');
        }
        return $result;
    }

    public static function append(array $filter, string $alias, array &$where, array &$params): void
    {
        if (!in_array($alias, ['i', 'pi'], true)) throw new \InvalidArgumentException('Neplatný alias dokladu.');
        foreach (['issue_date_from' => '>=', 'issue_date_to' => '<='] as $key => $operator) {
            if (!isset($filter[$key])) continue;
            $where[] = "$alias.issue_date $operator ?";
            $params[] = $filter[$key];
        }
    }
}
