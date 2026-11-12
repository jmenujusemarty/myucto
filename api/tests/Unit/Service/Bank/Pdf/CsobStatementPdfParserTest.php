<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Bank\Pdf;

use MyInvoice\Service\Bank\Pdf\CsobStatementPdfParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Parser PDF výpisu ČSOB. Testuje se přes seamy parseHeaderFromText()/
 * parseTransactionsFromText() na VYMYŠLENÉM textu (žádná reálná zákaznická data dle
 * pravidla feedback_test_data_local_only — účty, jména, VS i částky jsou syntetické),
 * jehož struktura odpovídá tomu, co Smalot\PdfParser::getText() reálně vrací
 * (tabulátory/mezery mezi sloupci, běžný zůstatek jako poslední hodnota na řádku).
 */
final class CsobStatementPdfParserTest extends TestCase
{
    public function testWrappedSepaRowDoesNotMergeIntoTheFollowingTransaction(): void
    {
        $text = $this->statement('1 000,00', '1 300,00', '500,00', '-200,00',
            "01.06.Příchozí úhrada SEPA, EUR v rámci\nEU/EHP\nTEST LTD.\t123 500,00 1 500,00\nIE91CITI99005123687003\n"
            . "02.06.Odchozí úhrada\tDODAVATEL\t124 -200,00 1 300,00\n123456789/0300\t10001 0308");
        $parsed = $this->parser()->parse('', $text);
        self::assertCount(2, $parsed['transactions']);
        self::assertSame(500.0, $parsed['transactions'][0]['amount']);
        self::assertSame(-200.0, $parsed['transactions'][1]['amount']);
        self::assertSame('2026-06-01', $parsed['transactions'][0]['posted_at']);
    }

    private function parser(): CsobStatementPdfParser
    {
        return new CsobStatementPdfParser(new NullLogger());
    }

    /** Obalí syntetické řádky transakcí do plné struktury výpisu (hlavička + patička). */
    private function statement(string $prev, string $curr, string $credit, string $debit, string $rows, string $currency = 'CZK'): string
    {
        return "VÝPIS Z ÚČTU Československá obchodní banka, a. s., Radlická 333/150, 150 57 Praha 5; www.csob.cz, Infolinka 800 300 300 Období: 1. 6. 2026 - 30. 6. 2026\n"
            . "Účet: 1234567/0300\n"
            . "Název účtu:Testovací s.r.o.\n"
            . "Rok/č. výpisu:2026/6\n"
            . "BIC: CEKOCZPP\n"
            . "Měna: {$currency}\n"
            . "Souhrnné informace\n"
            . "Počáteční zůstatek:\t{$prev}\n"
            . "Konečný zůstatek:\t{$curr}\n"
            . "Celkové příjmy:\t{$credit}\n"
            . "Celkové výdaje:\t{$debit}\n"
            . "Přehled pohybů na účtu od 1. 6. 2026 do 30. 6. 2026\n"
            . "Datum\nValuta\nOznačení platby\nProtiúčet nebo poznámka\nNázev protiúčtu\nVS\tKS SS\nIdentifikace Částka Zůstatek\n"
            . $rows . "\n"
            . "Prosíme Vás o včasné překontrolování uvedených údajů.\n";
    }

    public function testParsesHeader(): void
    {
        $text = $this->statement('10 000,00', '15 000,00', '10 000,00', '-5 000,00', '');
        $header = $this->parser()->parseHeaderFromText($text);

        self::assertSame('1234567', $header['account_number']);
        self::assertSame('2026-06-30', $header['statement_date']);
        self::assertSame('6', $header['statement_number']);
        self::assertSame('CZK', $header['currency']);
        self::assertSame(10000.0, $header['prev_balance']);
        self::assertSame(15000.0, $header['curr_balance']);
        self::assertSame(10000.0, $header['credit_total']);
        // debit_total je kladná magnituda (konzistentně s GpcParser/UI konvencí).
        self::assertSame(5000.0, $header['debit_total']);
    }

    public function testSequenceNumberIsNotMergedIntoAmount(): void
    {
        // Klíčová past ČSOB: sloupec Identifikace (pořadové číslo) je od částky oddělený
        // jen MEZEROU stejně jako oddělovač tisíců, takže „24 250 000,00" by se dalo číst
        // i jako 24 250 000,00 (24 mil.). Částka se proto NEbere z textu, ale z ROZDÍLU
        // po sobě jdoucích běžných zůstatků (poslední hodnota na řádku). „PRIKLAD 24"
        // navíc ověřuje, že číslo UVNITŘ názvu protistrany nesplete parser.
        $rows = "05.06.Příchozí úhrada okamžitá \tPRIKLAD 24 a.s.\t24 250 000,00 260 000,00\n"
              . "1111111111/0800\t5550001 0308\n"
              . "Faktura-5550001";
        $text = $this->statement('10 000,00', '260 000,00', '250 000,00', '0,00', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame('2026-06-05', $rowsOut[0]['posted_at']);
        self::assertSame(250000.0, $rowsOut[0]['amount']);
        self::assertSame('PRIKLAD 24 a.s.', $rowsOut[0]['counterparty_name']);
        self::assertSame('1111111111', $rowsOut[0]['counterparty_account']);
        self::assertSame('0800', $rowsOut[0]['counterparty_bank']);
        self::assertSame('5550001', $rowsOut[0]['variable_symbol']);
        self::assertSame('308', $rowsOut[0]['constant_symbol']);
    }

    public function testAmountsFollowRunningBalanceChainAcrossRows(): void
    {
        // Částka každého řádku = zůstatek_tohoto − zůstatek_předchozího (první řádek proti
        // Počátečnímu zůstatku z hlavičky). Míchá kredit i debet.
        $rows = "02.06.Příchozí úhrada \tALFA s.r.o.\t11 5 000,00 15 000,00\n"
              . "1111111111/0300\t5550011 0308\n"
              . "04.06.Odchozí úhrada \tBETA a.s.\t13 -2 000,00 13 000,00\n"
              . "2222222222/0800\t5550022 0308";
        $text = $this->statement('10 000,00', '13 000,00', '5 000,00', '-2 000,00', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(2, $rowsOut);
        self::assertSame(5000.0, $rowsOut[0]['amount']);
        self::assertSame(-2000.0, $rowsOut[1]['amount']);
    }

    public function testInterestRateChangeRowIsSkippedAsInformational(): void
    {
        // Informativní řádek „Změna úrokové sazby z 20,00 % p. a. na" NENÍ transakce —
        // jeho „20,00" je procento uprostřed textu, ne běžný zůstatek na konci řádku.
        // Bez pojistky „poslední peníz musí být na konci řádku" by se falešný zůstatek
        // vecpal do řetězu a self-check by ho kvůli teleskopování rozdílů nezachytil.
        $rows = "02.06.Bezhotovostní převod EB \tALFA s.r.o.\t11 5 000,00 15 000,00\n"
              . "1111111111/0300\t5550011 0308\n"
              . "02.06.Změna úrokové sazby z 20,00 % p. a. na \n"
              . "0,00 % p. a. \n"
              . "12";
        $text = $this->statement('10 000,00', '15 000,00', '5 000,00', '0,00', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame(5000.0, $rowsOut[0]['amount']);
    }

    public function testCardPaymentUsesMerchantFromMistoLine(): void
    {
        // Karetní transakce nemá název protistrany na prvním řádku — obchodník je v „Místo: …".
        $rows = "04.06.Transakce platební kartou \t22 -1 234,00 8 766,00\n"
              . "200000000 1111111111111111\n"
              . "Místo: Test Merchant Prague\n"
              . "Částka: 1234.00 CZK 02.06.2026\n"
              . "Praha 9";
        $text = $this->statement('10 000,00', '8 766,00', '0,00', '-1 234,00', $rows);

        $rowsOut = $this->parser()->parseTransactionsFromText($text);
        self::assertCount(1, $rowsOut);
        self::assertSame(-1234.0, $rowsOut[0]['amount']);
        self::assertSame('Test Merchant Prague', $rowsOut[0]['counterparty_name']);
        self::assertNull($rowsOut[0]['counterparty_account']);
    }

    public function testEurStatementWithTabSeparatedAmounts(): void
    {
        // EUR výpis: pořadí/částka/zůstatek jsou oddělené TABEM (ne mezerou) a částky
        // jsou malé. Měna se propíše z hlavičky do každé transakce.
        $rows = "02.06.Bezhotovostní převod EB \tALFA s.r.o.\t11\t10,00\t15,00\n"
              . "1111111111/0300\t1\t2938";
        $text = $this->statement('5,00', '15,00', '10,00', '0,00', $rows, 'EUR');

        $result = $this->parser()->parse('%PDF-fake', $text);
        self::assertCount(1, $result['transactions']);
        self::assertSame(10.0, $result['transactions'][0]['amount']);
        self::assertSame('EUR', $result['transactions'][0]['currency']);
    }

    public function testSelfCheckRejectsWhenFinalBalanceDoesNotMatchHeader(): void
    {
        // Konečný zůstatek v hlavičce nesedí na poslední běžný zůstatek v řádcích →
        // řetěz zůstatků je neúplný/špatně přečtený → parse() musí selhat.
        $rows = "02.06.Příchozí úhrada \tALFA s.r.o.\t11 5 000,00 15 000,00\n"
              . "1111111111/0300\t5550011 0308";
        $text = $this->statement('10 000,00', '99 999,00', '5 000,00', '0,00', $rows);

        $this->expectException(\RuntimeException::class);
        $this->parser()->parse('%PDF-fake', $text);
    }

    public function testEmptyMonthWithZeroTransactionsIsValid(): void
    {
        $text = $this->statement('1 000,00', '1 000,00', '0,00', '0,00', '');
        $result = $this->parser()->parse('%PDF-fake', $text);
        self::assertSame([], $result['transactions']);
    }

    public function testSupportsDetectsCsob(): void
    {
        $parser = $this->parser();
        self::assertTrue($parser->supports("VÝPIS Z ÚČTU Československá obchodní banka, a. s.\nwww.csob.cz\n"));
        self::assertTrue($parser->supports("VÝPIS Z ÚČTU\nBIC: CEKOCZPP\n"));
        self::assertFalse($parser->supports("Nějaký jiný bankovní výpis\n"));
        self::assertFalse($parser->supports("VÝPIS PERIODICKÝ\nKomerční banka, a.s.\n"));
    }
}
