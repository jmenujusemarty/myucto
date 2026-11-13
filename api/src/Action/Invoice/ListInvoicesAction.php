<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\RequestAuthorization;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\InvoiceListDetailsRepository;
use MyInvoice\Repository\InvoiceListExtrasRepository;
use MyInvoice\Service\Document\DocumentViewerResolver;
use MyInvoice\Repository\DimensionListSummaryRepository;
use MyInvoice\Service\Accounting\DocumentLockService;
use MyInvoice\Service\Report\InvoiceKhSections;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListInvoicesAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Config $config,
        private readonly DocumentLockService $locks,
        private readonly InvoiceKhSections $khSections,
        private readonly InvoiceListDetailsRepository $listDetails,
        private readonly InvoiceListExtrasRepository $listExtras,
        private readonly DimensionListSummaryRepository $dimensionSummaries,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $filter = (array) ($q['filter'] ?? []);
        try {
            $issueDates = \MyInvoice\Service\Invoice\InvoiceIssueDateFilter::parse($filter);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 422);
        }
        if (($filter['include_dimensions'] ?? null) === '1'
            && !RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        if ((($filter['include_journal_notes'] ?? null) === '1'
            || ($filter['include_posting_accounts'] ?? null) === '1'
            || in_array($q['sort_key'] ?? '', ['journal_notes', 'debit_accounts', 'credit_accounts'], true))
            && !RequestAuthorization::allows($request, 'accounting', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }
        if (($filter['include_document_tags'] ?? null) === '1'
            && !RequestAuthorization::allows($request, 'documents', AccessLevel::READ)) {
            return Json::error($response, 'forbidden', 'Pro tuto akci nemáš oprávnění.', 403);
        }

        // Neuhrazené K DATU X (task #4) — na rozdíl od `unpaid_only` (dnešní status) jde
        // o historický dotaz: doklad vystavený do X, u kterého k X nebyl uhrazen celý
        // amount_to_pay. Validace shodná se SaldoAction::isDate — chybný formát je 422,
        // ne tiché "nefiltrovat" (uživatel by si myslel, že vidí správný řez).
        $unpaidAsOf = isset($filter['unpaid_as_of']) && is_scalar($filter['unpaid_as_of'])
            ? trim((string) $filter['unpaid_as_of'])
            : '';
        if ($unpaidAsOf !== '' && !$this->isDate($unpaidAsOf)) {
            return Json::error($response, 'validation_failed', "filter[unpaid_as_of] musí být datum (YYYY-MM-DD).", 422);
        }

        $filters = $issueDates + [
            'q'             => isset($q['q']) ? trim((string) $q['q']) : '',
            'status'        => $filter['status']      ?? null,
            'type'          => $filter['type']        ?? null,
            'client_id'     => $filter['client_id']   ?? null,
            'project_id'    => $filter['project_id']  ?? null,
            'year'          => $filter['year']        ?? null,
            'month'         => $filter['month']       ?? null,
            'date_from'     => $filter['date_from']   ?? null,
            'date_to'       => $filter['date_to']     ?? null,
            'currency'      => $filter['currency']    ?? null,
            'unpaid_only'   => !empty($filter['unpaid_only']),
            'overdue'       => !empty($filter['overdue']),
            'unpaid_as_of'  => $unpaidAsOf !== '' ? $unpaidAsOf : null,
            'booked'        => $filter['booked']      ?? null,
            // Kategorie tržby, čárkou oddělené seznamy: `revenue_category_id` = ponechat jen
            // tyhle, `revenue_category_exclude` = tyhle skrýt. Sentinel `none` = doklad bez
            // kategorie. Ověření vlastnictví i sémantiku NULL řeší repository, ať filtr
            // a jeho SQL nemají dvě různá pravidla.
            'revenue_category_id'      => $filter['revenue_category_id']      ?? null,
            'revenue_category_exclude' => $filter['revenue_category_exclude'] ?? null,
            // Doklady s řádkem k ručnímu posouzení místa plnění (OSS). Rozsah říká, kde
            // řádek leží: 'domestic' = mimo OSS (tiše v přiznání na ř. 1/2), 'oss' = v OSS
            // podání s otazníkem nad zemí či typem sazby, 'any' = obojí. Legacy `1`/`true`
            // se mapuje na 'any' (viz InvoiceRepository::ossReviewScope()) — bez rozsahu
            // by uživatel viděl jen půlku sporných řádků a druhou by měl jen v reportu
            // importu, který po zavření stránky zmizí.
            'oss_review'  => is_scalar($filter['oss_review'] ?? null) && !empty($filter['oss_review'])
                ? (string) $filter['oss_review']
                : null,
            'supplier_id' => (int) $request->getAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, 0),
            'group_by_month' => !is_scalar($filter['group_by_month'] ?? null) || (string) $filter['group_by_month'] !== '0',
            'sort_key' => is_scalar($q['sort_key'] ?? null) ? (string) $q['sort_key'] : '',
            'sort_dir' => is_scalar($q['sort_dir'] ?? null) ? (string) $q['sort_dir'] : '',
        ];

        // Status / type může být čárkou oddělené — split
        foreach (['status', 'type'] as $f) {
            if (is_string($filters[$f]) && $filters[$f] !== '' && str_contains($filters[$f], ',')) {
                $filters[$f] = explode(',', $filters[$f]);
            }
        }

        $page = max(1, (int) ($q['page'] ?? 1));
        $default = (int) $this->config->get('pagination.invoices_per_page', 50);
        $perPage = min(200, max(5, (int) ($q['per_page'] ?? $default)));

        $result = $this->repo->listGroupedByMonth($filters, $page, $perPage);

        // Jednotný kontrakt zámku per-row (Epic F6, §4.5) — batch přes lockedMapForSources,
        // jeden IN dotaz na posted zápisy, žádné N+1.
        $ids = [];
        foreach ($result['data'] as $group) {
            foreach ($group['invoices'] as $row) {
                $ids[] = (int) $row['id'];
            }
        }
        if ($ids !== []) {
            $map = $this->locks->lockedMapForSources((int) $filters['supplier_id'], 'invoice', $ids);
            $includeVat = ($filter['include_vat_breakdown'] ?? null) === '1';
            $includePosting = ($filter['include_posting_accounts'] ?? null) === '1';
            $details = $includeVat || $includePosting
                ? $this->listDetails->forDocuments((int) $filters['supplier_id'], 'invoice', $ids, $includeVat, $includePosting)
                : [];
            $extras = $this->listExtras->forDocuments(
                (int) $filters['supplier_id'], 'invoice', $ids,
                ($filter['include_journal_notes'] ?? null) === '1',
                ($filter['include_document_tags'] ?? null) === '1' ? DocumentViewerResolver::fromRequest($request) : null,
            );
            $dimensionLabels = ($filter['include_dimensions'] ?? null) === '1'
                ? $this->dimensionSummaries->forDocuments((int) $filters['supplier_id'], 'invoice', $ids)
                : [];
            foreach ($result['data'] as &$group) {
                foreach ($group['invoices'] as &$row) {
                    $lock = $map[(int) $row['id']] ?? null;
                    if ($lock !== null) {
                        $row['locked'] = $lock->toArray();
                    }
                    $row += $extras[(int) $row['id']] ?? [];
                    if (isset($details[(int) $row['id']])) {
                        $row += $details[(int) $row['id']];
                    }
                    if (($filter['include_dimensions'] ?? null) === '1') {
                        $row['dimension_labels'] = $dimensionLabels[(int) $row['id']] ?? [];
                    }
                }
            }
            unset($group, $row);
        }

        if (($filter['include_kh'] ?? null) === '1' || ($filter['include_vat_classification'] ?? null) === '1') {
            $this->khSections->addToGroups((int) $filters['supplier_id'], $result['data'], 'issued', ($filter['include_vat_classification'] ?? null) === '1');
        }

        return Json::ok($response, $result);
    }

    private function isDate(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }
}
