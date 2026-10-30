<?php
declare(strict_types=1);
namespace MyInvoice\Service\Invoice;

/** Úzký kontrakt pro koncepty; nikdy nepřijímá stav vystavení ani odeslání. */
final class RebillingPolicy
{
    public static function validate(array $body): void
    {
        $allowed = ['client_id','month','mode','source_key','source_hash','invoice_number','lines'];
        if (array_diff(array_keys($body), $allowed) !== []
            || !preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', (string) ($body['month'] ?? ''))
            || !in_array($body['mode'] ?? '', ['monthly','document'], true)
            || !is_int($body['client_id'] ?? null) || $body['client_id'] <= 0
            || !preg_match('/^[a-zA-Z0-9:._\/-]{1,200}$/D', (string) ($body['source_key'] ?? ''))
            || !preg_match('/^[a-f0-9]{64}$/D', (string) ($body['source_hash'] ?? ''))
            || !preg_match('/^[a-zA-Z0-9._\/-]{1,100}$/D', (string) ($body['invoice_number'] ?? ''))
            || !is_array($body['lines'] ?? null) || count($body['lines']) < 1 || count($body['lines']) > 100) {
            throw new \InvalidArgumentException('Neplatný požadavek přefakturace.');
        }
        $beer = 0; $deposits = 0;
        foreach ($body['lines'] as $line) {
            if (!is_array($line) || array_diff(array_keys($line), ['kind','description','quantity','unitPriceCents','vatRate']) !== []
                || !is_int($line['quantity'] ?? null) || $line['quantity'] <= 0 || $line['quantity'] > 100000
                || !is_int($line['unitPriceCents'] ?? null) || $line['unitPriceCents'] === 0 || abs($line['unitPriceCents']) > 100000000
                || !is_string($line['description'] ?? null) || mb_strlen($line['description']) > 200) {
                throw new \InvalidArgumentException('Neplatná položka přefakturace.');
            }
            $kind = $line['kind'] ?? ''; $price = $line['unitPriceCents']; $q = $line['quantity'] * ($price < 0 ? -1 : 1);
            $descriptions = ['beer_keg'=>'CVAUD 11 - 50l KEG','keg_delivery'=>'Záloha KEG 50l','keg_return'=>'Záloha KEG 50l (vrácené)',
                'crate_delivery'=>'Záloha přepravka 20x0,33l','crate_return'=>'Záloha přepravka 20x0,33l (vrácené)',
                'bottle_delivery'=>'Záloha lahev 0,33l','bottle_return'=>'Záloha lahev 0,33l (vrácené)'];
            if (!isset($descriptions[$kind]) || $line['description'] !== $descriptions[$kind]
                || ($line['vatRate'] ?? null) !== ($kind === 'beer_keg' ? 21 : 0)
                || (str_ends_with($kind, '_return') && $price >= 0)
                || (str_starts_with($kind, 'keg_') && abs($price) !== 150000)) {
                throw new \InvalidArgumentException('Neplatné znaménko, DPH nebo mapování zálohy.');
            }
            if ($kind === 'beer_keg') $beer += $q;
            if ($kind === 'keg_delivery') $deposits += $q;
        }
        if ($beer !== $deposits) throw new \InvalidArgumentException('Počet KEG neodpovídá dodaným zálohám.');
    }

    public static function snapshot(array $invoice): string
    {
        // Čteme skutečný obsah včetně ID řádků. Ruční editace se nikdy nepřepíše.
        return hash('sha256', json_encode($invoice, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
