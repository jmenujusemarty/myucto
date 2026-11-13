<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\ListInvoicesAction;
use MyInvoice\Action\PurchaseInvoice\ListPurchaseInvoicesAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Task #4 — filtr „neuhrazené k datu X" (`filter[unpaid_as_of]`), akční vrstva.
 *
 * Doplňuje {@see \MyInvoice\Tests\Integration\Repository\ListGroupedByMonthTest}
 * (ta ověřuje samotnou SQL definici — plně/pozdě/částečně uhrazeno). Tenhle test
 * ověřuje, co k SQL definici přidává akce: validaci formátu data (422, ne tiché
 * "nefiltrovat") a to, že `filter[unpaid_as_of]` z query stringu skutečně doteče
 * do `InvoiceRepository`/`PurchaseInvoiceRepository` (end-to-end přes GET).
 */
#[Group('integration')]
final class UnpaidAsOfFilterActionTest extends TestCase
{
    private Connection $db;
    private ListInvoicesAction $invoicesAction;
    private ListPurchaseInvoicesAction $purchasesAction;
    private int $supplierId;
    private int $clientId;
    private int $currencyId;
    private int $userId;
    private int $countryId;
    private bool $inTx = false;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }

        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->invoicesAction = $container->get(ListInvoicesAction::class);
            $this->purchasesAction = $container->get(ListPurchaseInvoicesAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->countryId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query(
            "SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if (in_array(0, [$this->supplierId, $this->userId, $this->countryId, $this->currencyId], true)) {
            $this->markTestSkipped('Chybí základní data.');
        }

        $pdo->beginTransaction();
        $this->inTx = true;

        $stmt = $pdo->prepare(
            'INSERT INTO clients
                (supplier_id, company_name, street, city, zip, country_id, main_email,
                 language, currency_default_id, is_customer, is_vendor)
             VALUES (?, "Task4 Action Test Klient", "Test 1", "Praha", "11000", ?, "task4-action@example.test",
                     "cs", ?, 1, 1)'
        );
        $stmt->execute([$this->supplierId, $this->countryId, $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->inTx) {
            if ($this->db->pdo()->inTransaction()) {
                $this->db->pdo()->rollBack();
            }
            $this->db->close();
        }
    }

    public function testInvalidDateFormatIsRejectedWith422(): void
    {
        $response = $this->call($this->invoicesAction, '/api/invoices', ['filter' => ['unpaid_as_of' => '30.6.2026']]);
        self::assertSame(422, $response['status']);
        self::assertSame('validation_failed', $response['body']['error']['code'] ?? null);

        $response = $this->call($this->purchasesAction, '/api/purchase-invoices', ['filter' => ['unpaid_as_of' => 'not-a-date']]);
        self::assertSame(422, $response['status']);
        self::assertSame('validation_failed', $response['body']['error']['code'] ?? null);
    }

    public function testValidUnpaidAsOfFiltersInvoicesEndToEnd(): void
    {
        $paidBefore = $this->invoice('2003-01-05', 1210.0, 'paid');
        $this->invoicePayment($paidBefore, '2003-01-20', 1210.0);

        $stillUnpaid = $this->invoice('2003-01-06', 1210.0, 'issued');

        $response = $this->call($this->invoicesAction, '/api/invoices', [
            'filter' => ['unpaid_as_of' => '2003-01-31', 'client_id' => $this->clientId],
        ]);

        self::assertSame(200, $response['status']);
        $ids = $this->collectIds($response['body']['data'] ?? [], 'invoices');
        self::assertSame([$stillUnpaid], $ids, 'jen doklad bez plné úhrady k asOf projde přes akční vrstvu.');
        self::assertNotContains($paidBefore, $ids);
    }

    public function testValidUnpaidAsOfFiltersPurchaseInvoicesEndToEnd(): void
    {
        $paidBefore = $this->purchase('2003-01-05', 1210.0, 'paid');
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET paid_at = ? WHERE id = ?')
            ->execute(['2003-01-20', $paidBefore]);

        $stillUnpaid = $this->purchase('2003-01-06', 1210.0, 'received');

        $response = $this->call($this->purchasesAction, '/api/purchase-invoices', [
            'filter' => ['unpaid_as_of' => '2003-01-31', 'vendor_id' => $this->clientId],
        ]);

        self::assertSame(200, $response['status']);
        $ids = $this->collectIds($response['body']['data'] ?? [], 'invoices');
        self::assertSame([$stillUnpaid], $ids, 'jen doklad bez plné úhrady k asOf projde přes akční vrstvu.');
        self::assertNotContains($paidBefore, $ids);
    }

    public function testIssueDateRangeIsIndependentOfTaxMonthForBothLists(): void
    {
        foreach (['sales', 'purchase'] as $kind) {
            $sales = $kind === 'sales';
            $inside = $sales ? $this->invoice('2003-10-01', 1210.0, 'issued') : $this->purchase('2003-10-01', 1210.0, 'received');
            $outside = $sales ? $this->invoice('2003-11-03', 1210.0, 'issued') : $this->purchase('2003-11-03', 1210.0, 'received');
            $table = $sales ? 'invoices' : 'purchase_invoices';
            $this->db->pdo()->prepare("UPDATE $table SET tax_date = ? WHERE id = ?")->execute(['2003-09-30', $inside]);
            $this->db->pdo()->prepare("UPDATE $table SET tax_date = ? WHERE id = ?")->execute(['2003-10-31', $outside]);
            $action = $sales ? $this->invoicesAction : $this->purchasesAction;
            $uri = $sales ? '/api/invoices' : '/api/purchase-invoices';
            $response = $this->call($action, $uri, ['filter' => [
                'issue_date_from' => '2003-10-01', 'issue_date_to' => '2003-10-31',
                ($sales ? 'client_id' : 'vendor_id') => $this->clientId,
            ]]);
            self::assertSame(200, $response['status']);
            self::assertSame([$inside], $this->collectIds($response['body']['data'] ?? [], 'invoices'));
            self::assertSame(422, $this->call($action, $uri, ['filter' => ['issue_date_from' => '2003-02-30']])['status']);
        }
    }

    /** @param array<string,mixed> $query */
    private function call(callable $action, string $uri, array $query): array
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', $uri)
            ->withQueryParams($query)
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);

        $response = $action($request, new Psr7Response());
        $response->getBody()->rewind();

        return [
            'status' => $response->getStatusCode(),
            'body'   => json_decode((string) $response->getBody(), true) ?: [],
        ];
    }

    /**
     * @param list<array<string,mixed>> $groups
     * @return list<int>
     */
    private function collectIds(array $groups, string $itemsKey): array
    {
        $ids = [];
        foreach ($groups as $g) {
            foreach (($g[$itemsKey] ?? []) as $row) {
                $ids[] = (int) $row['id'];
            }
        }
        sort($ids);
        return $ids;
    }

    private function invoice(string $issueDate, float $gross, string $status): int
    {
        $net = round($gross / 1.21, 2);
        $vat = round($gross - $net, 2);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoices
                (supplier_id, varsymbol, invoice_type, client_id, issue_date, tax_date, due_date,
                 currency_id, exchange_rate, reverse_charge, total_without_vat, total_vat, total_with_vat,
                 status, vat_classification_code, created_by)
             VALUES (?, ?, "invoice", ?, ?, ?, ?, ?, 1.0, 0, ?, ?, ?, ?, "1", ?)'
        );
        $stmt->execute([
            $this->supplierId, 'ACT-' . uniqid(), $this->clientId, $issueDate, $issueDate, $issueDate,
            $this->currencyId, $net, $vat, $gross, $status, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function invoicePayment(int $invoiceId, string $paidOn, float $amount): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, source)
             VALUES (?, ?, ?, ?, "CZK", "manual")'
        )->execute([$this->supplierId, $invoiceId, $paidOn, $amount]);
    }

    private function purchase(string $issueDate, float $gross, string $status): int
    {
        $net = round($gross / 1.21, 2);
        $vat = round($gross - $net, 2);
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, varsymbol, vendor_invoice_number, document_kind,
                 issue_date, tax_date, due_date, received_at, currency_id, exchange_rate, reverse_charge,
                 vendor_snapshot, total_without_vat, total_vat, total_with_vat, status,
                 vat_classification_code, vat_deduction, created_by)
             VALUES (?, ?, ?, ?, "invoice", ?, ?, ?, ?, ?, 1.0, 0, "{}", ?, ?, ?, ?, "40", "full", ?)'
        );
        $stmt->execute([
            $this->supplierId, $this->clientId, 'ACTP-' . uniqid(), 'ACTP-' . uniqid(),
            $issueDate, $issueDate, $issueDate, $issueDate,
            $this->currencyId, $net, $vat, $gross, $status, $this->userId,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }
}
