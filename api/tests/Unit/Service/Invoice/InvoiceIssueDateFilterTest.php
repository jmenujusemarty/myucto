<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\InvoiceIssueDateFilter;
use PHPUnit\Framework\TestCase;

final class InvoiceIssueDateFilterTest extends TestCase
{
    public function testFiltersByIssueDateDespiteDifferentTaxMonth(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE invoices (id INTEGER, supplier_id INTEGER, issue_date TEXT, tax_date TEXT)');
        $pdo->exec("INSERT INTO invoices VALUES (1,17,'2026-10-01','2026-09-30'),(2,17,'2026-10-31','2026-11-01'),(3,17,'2026-11-03','2026-10-31'),(4,18,'2026-10-15','2026-10-15')");
        $filter = InvoiceIssueDateFilter::parse(['issue_date_from' => '2026-10-01', 'issue_date_to' => '2026-10-31']);
        $where = ['i.supplier_id = ?'];
        $params = [17];
        InvoiceIssueDateFilter::append($filter, 'i', $where, $params);
        $stmt = $pdo->prepare('SELECT id FROM invoices i WHERE ' . implode(' AND ', $where) . ' ORDER BY id');
        $stmt->execute($params);
        self::assertSame([1, 2], $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testRejectsInvalidAndReversedRanges(): void
    {
        foreach ([['issue_date_from' => '2026-02-30'], ['issue_date_to' => ['2026-01-01']],
            ['issue_date_from' => '2026-11-01', 'issue_date_to' => '2026-10-31']] as $input) {
            try {
                InvoiceIssueDateFilter::parse($input);
                self::fail('Neplatný rozsah musí být odmítnutý.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('issue_date', $e->getMessage());
            }
        }
    }

    public function testAbsentRangeDoesNotChangeExistingTaxFilters(): void
    {
        $where = ['i.effective_tax_date >= ?'];
        $params = ['2026-10-01'];
        InvoiceIssueDateFilter::append(InvoiceIssueDateFilter::parse([]), 'i', $where, $params);
        self::assertSame(['i.effective_tax_date >= ?'], $where);
        self::assertSame(['2026-10-01'], $params);
    }
}
