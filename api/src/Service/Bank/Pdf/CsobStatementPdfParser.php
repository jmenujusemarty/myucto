<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank\Pdf;

use Psr\Log\LoggerInterface;

/**
 * Parser PDF výpisu od ČSOB (Československá obchodní banka). ČSOB nabízí sice i jiné
 * formáty, ale uživatel má k dispozici jen PDF — parsujeme ho deterministicky (žádné
 * AI, finanční data). Text extrahuje registry (Smalot\PdfParser), tahle třída ho jen
 * rozparsuje. Protějšek {@see CreditasStatementPdfParser} pro layout ČSOB.
 *
 * Layout (ověřeno na reálných výpisech CZK i EUR): hlavička s labely „Účet:",
 * „Období:", „Počáteční/Konečný zůstatek:", „Celkové příjmy/výdaje:", pak tabulka
 * transakcí. Každá transakce začíná řádkem s datem ve tvaru „DD.MM." (rok NENÍ v řádku,
 * bere se z období výpisu). PRVNÍ řádek transakce nese `typ [\t protistrana] [\t] pořadí
 * částka zůstatek` — poslední dvě peněžní hodnoty na řádku jsou částka a běžný zůstatek.
 * Další (detailní) řádky nesou protiúčet/kód banky, VS/KS/SS, poznámku, u karetních
 * transakcí „Místo:"/„Částka:". Sloupce se v PDF textovém streamu slepují tabem nebo
 * mezerou (nekonzistentně) — parsování se proto neopírá o pozici, ale o tokeny.
 *
 * Self-check: součet transakcí musí sedět na `curr_balance - prev_balance` z hlavičky
 * (na haléř přesně — nativní PDF text, ne OCR) — jinak parse() vyhodí, ať se špatně
 * přečtená finanční data nikdy neuloží.
 */
final class CsobStatementPdfParser implements BankStatementPdfParserInterface
{
    /** Peněžní hodnota: volitelné znaménko, tisíce oddělené mezerou/NBSP, čárka desetinná. */
    private const MONEY = '-?\d{1,3}(?:[\x{00A0} ]\d{3})*,\d{2}';

    private const SKIP_LINE_PATTERNS = [
        // Opakovaná záhlaví sloupců (na každé stránce znovu).
        '/^Datum$/u',
        '/^Valuta$/u',
        '/^Označení platby$/u',
        '/^Protiúčet nebo poznámka$/u',
        '/^Název protiúčtu$/u',
        '/^VS\s+KS\s+SS$/u',
        '/^Identifikace\s+Částka\s+Zůstatek$/u',
        '/^Přehled pohybů na účtu/u',
        // Patičky / opakované hlavičky výpisu na dalších stránkách.
        '/^Strana:/u',
        '/^Období:/u',
        '/^Účet:/u',
        '/^Název účtu:/u',
        '/^VÝPIS Z ÚČTU/u',
    ];

    public function __construct(private readonly LoggerInterface $logger) {}

    public function key(): string
    {
        return 'csob';
    }

    public function supports(string $text): bool
    {
        return str_contains($text, 'VÝPIS Z ÚČTU')
            && (str_contains($text, 'www.csob.cz')
                || str_contains($text, 'CEKOCZPP')
                || str_contains($text, 'Československá obchodní banka'));
    }

    public function parse(string $pdfBytes, string $text): array
    {
        $header = $this->parseHeaderFromText($text);
        $transactions = $this->parseTransactionsFromText($text);

        // Self-check: součet transakcí musí souhlasit s pohybem zůstatku na haléř přesně.
        $sum = 0.0;
        foreach ($transactions as $tx) $sum += (float) $tx['amount'];
        $expected = round($header['curr_balance'] - $header['prev_balance'], 2);
        if (abs(round($sum, 2) - $expected) > 0.01) {
            throw new \RuntimeException(sprintf(
                'ČSOB PDF: součet transakcí (%.2f) nesedí na změnu zůstatku dle hlavičky (%.2f). Parsování zamítnuto.',
                $sum,
                $expected,
            ));
        }

        $currency = $header['currency'] ?? 'CZK';
        foreach ($transactions as &$tx) {
            $tx['currency'] = $currency;
        }
        unset($tx);
        unset($header['currency']);

        return ['header' => $header, 'transactions' => $transactions];
    }

    /**
     * @return array{account_number:string, statement_date:string, statement_number:string,
     *   prev_balance:float, curr_balance:float, debit_total:float, credit_total:float, currency:?string}
     */
    public function parseHeaderFromText(string $text): array
    {
        $money = '(' . self::MONEY . ')';

        if (!preg_match('/Účet:\s*([\d\-]+)\/(\d{3,4})/u', $text, $m)) {
            throw new \RuntimeException('ČSOB PDF: chybí "Účet:" v hlavičce.');
        }
        $accountNumber = $m[1];

        // Období: „1. 4. 2024 - 30. 4. 2024" (mezery kolem teček) → konec období = datum výpisu.
        if (!preg_match('/Období:\s*\d{1,2}\.\s*\d{1,2}\.\s*\d{4}\s*-\s*(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/u', $text, $m)) {
            throw new \RuntimeException('ČSOB PDF: chybí "Období:" v hlavičce.');
        }
        $statementDate = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);

        // „Rok/č. výpisu:2024/2" → pořadové číslo výpisu.
        $statementNumber = preg_match('/Rok\/č\. výpisu:\s*\d{4}\/(\d+)/u', $text, $m) ? $m[1] : '';
        $currency = preg_match('/Měna:\s*([A-Z]{3})/u', $text, $m) ? $m[1] : null;

        if (!preg_match('/Počáteční zůstatek:\s*' . $money . '/u', $text, $m)) {
            throw new \RuntimeException('ČSOB PDF: chybí "Počáteční zůstatek:" v hlavičce.');
        }
        $prevBalance = $this->num($m[1]);

        if (!preg_match('/Konečný zůstatek:\s*' . $money . '/u', $text, $m)) {
            throw new \RuntimeException('ČSOB PDF: chybí "Konečný zůstatek:" v hlavičce.');
        }
        $currBalance = $this->num($m[1]);

        $creditTotal = preg_match('/Celkové příjmy:\s*' . $money . '/u', $text, $m) ? $this->num($m[1]) : 0.0;
        // debit_total je (stejně jako u GpcParser) kladná magnituda — UI si znaménko doplní.
        $debitTotal = preg_match('/Celkové výdaje:\s*' . $money . '/u', $text, $m) ? abs($this->num($m[1])) : 0.0;

        return [
            'account_number'   => $accountNumber,
            'statement_date'   => $statementDate,
            'statement_number' => $statementNumber,
            'prev_balance'     => $prevBalance,
            'curr_balance'     => $currBalance,
            'debit_total'      => $debitTotal,
            'credit_total'     => $creditTotal,
            'currency'         => $currency,
        ];
    }

    /**
     * Testovací seam — vytěží transakce z prostého textu (bez PDF bytes / self-checku).
     * Rok transakcí (řádky nesou jen „DD.MM.") se bere z období výpisu v hlavičce textu.
     *
     * @return list<array<string,mixed>>
     */
    public function parseTransactionsFromText(string $text): array
    {
        $year = preg_match('/Období:\s*\d{1,2}\.\s*\d{1,2}\.\s*\d{4}\s*-\s*\d{1,2}\.\s*\d{1,2}\.\s*(\d{4})/u', $text, $ym)
            ? (int) $ym[1]
            : (int) date('Y');

        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];

        // Tabulka začíná až za prvním sloupcovým záhlavím „Identifikace Částka Zůstatek".
        $startIdx = 0;
        foreach ($lines as $i => $ln) {
            if (preg_match('/^Identifikace\s+Částka\s+Zůstatek$/u', trim((string) $ln))) { $startIdx = $i + 1; break; }
        }
        $lines = array_slice($lines, $startIdx);

        // Rozsekat na slice = řádky jedné transakce. Nový slice začíná řádkem s datem
        // „DD.MM.". Patičkou „Prosíme Vás…" tabulka končí. Opakovaná záhlaví/patičky
        // uvnitř (page break) přeskakujeme.
        $slices = [];
        $current = [];
        foreach ($lines as $raw) {
            $t = trim((string) preg_replace('/\x{00A0}/u', ' ', (string) $raw));
            if ($t === '') continue;
            if (preg_match('/^Prosíme Vás/u', $t)) break;
            if ($this->isSkipLine($t)) continue;

            if (preg_match('/^\d{1,2}\.\d{1,2}\./u', $t)) {
                if ($current !== []) $slices[] = $current;
                $current = [$t];
            } elseif ($current !== []) {
                $current[] = $t;
            }
        }
        if ($current !== []) $slices[] = $current;

        // Částka se NEbere z textu přímo: sloupce „Identifikace <pořadí> <částka> <zůstatek>"
        // jsou v PDF streamu oddělené mezerou stejně jako oddělovač tisíců, takže pořadové
        // číslo se slévá s částkou do jednoho čísla (pořadí „24" + částka „250 000,00" →
        // „24 250 000,00" = 24 mil.).
        // Řešení: brát POSLEDNÍ peněžní hodnotu na řádku = běžný zůstatek (ta je jednoznačná),
        // a částku odvodit z ROZDÍLU po sobě jdoucích zůstatků (řetěz od Počátečního zůstatku).
        // Bonus: self-check „součet == curr-prev" se tím stává kontrolou „poslední zůstatek ==
        // Konečný zůstatek" = ověří, že řetěz zůstatků je úplný a ve správném pořadí.
        $running = preg_match('/Počáteční zůstatek:\s*(' . self::MONEY . ')/u', $text, $pm)
            ? $this->num($pm[1])
            : 0.0;

        $rows = [];
        foreach ($slices as $slice) {
            $row = $this->parseSlice($slice, $year);
            if ($row === null) continue;
            $balance = $row['balance'];
            unset($row['balance']);
            $row['amount'] = round($balance - $running, 2);
            $running = $balance;
            $rows[] = $row;
        }
        return $rows;
    }

    private function isSkipLine(string $line): bool
    {
        foreach (self::SKIP_LINE_PATTERNS as $pat) {
            if (preg_match($pat, $line)) return true;
        }
        return false;
    }

    /**
     * @param list<string> $slice
     * @return array<string,mixed>|null Řádek s klíčem `balance` (běžný zůstatek); částku
     *   dopočítá volající z rozdílu zůstatků. Null pro informativní/neplatný slice.
     */
    private function parseSlice(array $slice, int $year): ?array
    {
        // SEPA labels can wrap over several physical PDF lines before the amount/balance columns.
        // Keep the financial row together so its amount cannot disappear into the next balance delta.
        $financialEnd = '/' . self::MONEY . '\s+' . self::MONEY . '$/u';
        if (!preg_match($financialEnd, $slice[0])) {
            $head = $slice[0];
            for ($i = 1; $i < min(count($slice), 4); $i++) {
                $head .= "\t" . $slice[$i];
                if (preg_match($financialEnd, $head)) {
                    $slice = array_merge([$head], array_slice($slice, $i + 1));
                    break;
                }
            }
        }
        // 1) První řádek: „DD.MM.<typ> [\t protistrana] [\t] pořadí <částka> <zůstatek>".
        if (!preg_match('/^(\d{1,2})\.(\d{1,2})\.(.*)$/u', $slice[0], $m)) {
            return null;
        }
        $day = (int) $m[1];
        $mon = (int) $m[2];
        if ($mon < 1 || $mon > 12 || $day < 1 || $day > 31) return null;
        $postingDate = sprintf('%04d-%02d-%02d', $year, $mon, $day);
        $rest = $m[3];

        // Peněžní hodnoty na řádku (s pozicemi). POSLEDNÍ = běžný zůstatek (jednoznačný —
        // greedy slévání pořadí+částky postihne jen PRVNÍ match, ne poslední). Řádek bez
        // peněžní hodnoty je informativní (např. „Změna úrokové sazby z 20,00 % p. a. na")
        // — přeskočit.
        if (!preg_match_all('/' . self::MONEY . '/u', $rest, $mm, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $moneyMatches = $mm[0];
        $last = $moneyMatches[count($moneyMatches) - 1];
        // Běžný zůstatek je VŽDY poslední token na řádku transakce. Když poslední peněžní
        // hodnota není na konci řádku, jde o procento uvnitř textu (informativní řádek
        // „Změna úrokové sazby z 20,00 % p. a. na") — NENÍ to transakce, přeskočit. (Bez
        // téhle pojistky by se falešný „zůstatek" vecpal do řetězu zůstatků a self-check
        // by ho kvůli teleskopování rozdílů nezachytil.)
        if (trim((string) substr($rest, $last[1] + strlen($last[0]))) !== '') {
            return null;
        }
        $balance = $this->num($last[0]);

        // Název protistrany: uříznout řádek na začátku peněžního bloku (pořadí+částka+zůstatek)
        // — u ≥2 hodnot je začátek u předposlední (pořadí bývá slepené s částkou).
        $cut = count($moneyMatches) >= 2
            ? $moneyMatches[count($moneyMatches) - 2][1]
            : $moneyMatches[count($moneyMatches) - 1][1];
        $rest = substr($rest, 0, $cut);
        // Odseknout případné samostatné pořadové číslo (celé číslo na konci).
        $rest = (string) preg_replace('/[\t ]*\d+[\t ]*$/u', '', $rest);

        $cells = array_values(array_filter(array_map('trim', explode("\t", $rest)), static fn ($c) => $c !== ''));
        // cells[0] = typ transakce (nepoužíváme — směr je ze znaménka částky),
        // poslední buňka (je-li víc než jedna) = název protistrany.
        $nameFromFirstLine = count($cells) >= 2 ? $cells[count($cells) - 1] : null;

        // 2) Detailní řádky.
        $account = null;
        $bankCode = null;
        $vs = null; $ks = null; $ss = null;
        $merchant = null;
        $texts = [];

        $n = count($slice);
        for ($i = 1; $i < $n; $i++) {
            $line = $slice[$i];

            // Protiúčet/kód banky, volitelně + symboly (VS KS SS) za tabem/mezerou.
            if (preg_match('/^([\dA-Za-z][\dA-Za-z\-]+)\/(\d{3,4})(.*)$/u', $line, $acm)) {
                $account = $acm[1];
                $bankCode = $acm[2];
                $this->assignSymbols(trim($acm[3]), $vs, $ks, $ss);
                continue;
            }
            // Explicitní „VS 12345" v poznámce.
            if ($vs === null && preg_match('/^VS\s+(\d+)/u', $line, $vm)) {
                $vs = ltrim($vm[1], '0') ?: null;
                continue;
            }
            // Karetní transakce: obchodník v „Místo: …".
            if (preg_match('/^Místo:\s*(.+)$/u', $line, $cm)) {
                $merchant = trim($cm[1]);
                continue;
            }
            $texts[] = $line;
        }

        $counterpartyName = $nameFromFirstLine ?? $merchant;
        $descParts = $texts;
        if ($merchant !== null && $counterpartyName !== $merchant) $descParts[] = $merchant;
        $description = $descParts !== [] ? mb_substr(implode(' | ', $descParts), 0, 255) : null;

        return [
            'posted_at'            => $postingDate,
            'balance'              => $balance,
            'variable_symbol'      => $vs,
            'constant_symbol'      => $ks,
            'specific_symbol'      => $ss,
            'counterparty_account' => $account,
            'counterparty_bank'    => $bankCode,
            'counterparty_name'    => $counterpartyName !== null ? mb_substr($counterpartyName, 0, 190) : null,
            'description'          => $description,
            'bank_ref'             => null,
        ];
    }

    /** Přiřadí VS/KS/SS z „1230001 0308 0" (mezerou/tabem oddělené) — jen dosud null pole. */
    private function assignSymbols(string $tail, ?string &$vs, ?string &$ks, ?string &$ss): void
    {
        if ($tail === '') return;
        $tokens = preg_split('/[\t ]+/u', $tail) ?: [];
        $nums = array_values(array_filter($tokens, static fn ($t) => preg_match('/^\d+$/', $t) === 1));
        if (isset($nums[0]) && $vs === null) $vs = ltrim($nums[0], '0') ?: null;
        if (isset($nums[1]) && $ks === null) $ks = ltrim($nums[1], '0') ?: null;
        if (isset($nums[2]) && $ss === null) $ss = ltrim($nums[2], '0') ?: null;
    }

    /** „1 234,56" / „-2 000 000,00" → float (mezery/NBSP oddělovač tisíců, čárka desetinná). */
    private function num(string $s): float
    {
        $s = str_replace(["\u{00A0}", ' '], '', $s);
        $s = str_replace(',', '.', $s);
        return (float) $s;
    }
}
