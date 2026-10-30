<?php
declare(strict_types=1);
namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Invoice\InvoiceDefaults;
use MyInvoice\Service\Invoice\InvoiceCalculator;
use MyInvoice\Service\Invoice\RebillingPolicy;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Validation\InvoiceValidation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Vlastník transakce a idempotence; nikde nevolá issue, e-mail ani účetní zápis. */
final class RebillingAction
{
    public function __construct(private readonly Connection $db, private readonly InvoiceRepository $repo,
        private readonly InvoiceDefaults $defaults, private readonly InvoiceCalculator $calculator, private readonly ActivityLogger $logger) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $token = (array) $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN, []);
        $sid = SupplierGuard::currentId($request);
        $clientId = (int) (getenv('REBILLING_CLIENT_ID') ?: 0);
        $configuredSupplier = (int) (getenv('REBILLING_SUPPLIER_ID') ?: 0);
        if (($token['scope'] ?? '') !== 'rebilling_drafts' || (int) ($token['supplier_id'] ?? 0) !== $sid
            || $sid !== $configuredSupplier || $clientId <= 0) {
            return Json::error($response, 'rebilling_forbidden', 'Přefakturace není nakonfigurována pro tento token.', 403);
        }
        $month = (string) ($request->getQueryParams()['month'] ?? '');
        if (!preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $month)) return Json::error($response, 'invalid_month', 'Neplatný měsíc.', 400);
        $pdo = $this->db->pdo();
        $client = $pdo->prepare('SELECT id FROM clients WHERE supplier_id=? AND id=?');
        $client->execute([$sid,$clientId]);
        if (!$client->fetchColumn()) return Json::error($response, 'invalid_client', 'Odběratel nepatří firmě.', 403);
        if ($request->getMethod() === 'GET') return Json::ok($response, ['invoices'=>$this->invoices($sid,$clientId,$month),'mappings'=>$this->mappings($sid,$clientId,$month)]);
        $body = (array) ($request->getParsedBody() ?? []);
        try { RebillingPolicy::validate($body); }
        catch (\InvalidArgumentException $e) { return Json::error($response, 'invalid_rebilling', $e->getMessage(), 422); }
        if ($body['client_id'] !== $clientId || $body['month'] !== $month
            || $request->getHeaderLine('Idempotency-Key') !== $body['source_key']) {
            return Json::error($response, 'scope_mismatch', 'Nesouhlasí odběratel, období nebo klíč operace.', 403);
        }
        $hash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
        $lockName = 'rebilling:' . $sid . ':' . $clientId;
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)'); $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) return Json::error($response, 'busy', 'Přefakturace právě běží.', 409);
        try {
            $pdo->beginTransaction();
            $receipt = $pdo->prepare('SELECT * FROM rebilling_receipts WHERE supplier_id=? AND source_key=? FOR UPDATE');
            $receipt->execute([$sid,$body['source_key']]); $prior = $receipt->fetch(\PDO::FETCH_ASSOC);
            if ($prior) {
                if (!hash_equals($prior['payload_hash'], $hash)) {
                    $pdo->rollBack(); return Json::error($response, 'source_changed', 'Doklad již byl přefakturován s jiným obsahem.', 409);
                }
                $pdo->commit();
                return Json::ok($response, ['receipt'=>$this->receipt($prior),'invoices'=>$this->invoices($sid,$clientId,$month),'mappings'=>$this->mappings($sid,$clientId,$month)]);
            }
            // Historická ruční fakturace není důkazem řádkové shody, ale musí zabránit druhému účtování.
            foreach ($this->invoices($sid,$clientId,$month) as $historical) {
                if (preg_match('/(?<![A-Za-z0-9])'.preg_quote($body['invoice_number'],'/').'(?![A-Za-z0-9])/', (string)($historical['note_below_items']??''))) {
                    $pdo->rollBack();return Json::error($response,'historical_source_match','Číslo dokladu je již uvedeno na existující faktuře. Ověřte přefakturaci.',409);
                }
            }
            $target = $pdo->prepare("SELECT i.id FROM invoices i JOIN rebilling_receipts r ON r.supplier_id=i.supplier_id AND r.invoice_id=i.id
                WHERE i.supplier_id=? AND i.client_id=? AND i.issue_date>=? AND i.issue_date<? AND i.status='draft'
                ORDER BY i.id DESC LIMIT 1 FOR UPDATE");
            [$from,$to] = $this->period($month);
            $target->execute([$sid,$clientId,$from,$to]);
            $invoiceId = $body['mode'] === 'monthly' ? (int) $target->fetchColumn() : 0;
            $existingItems = [];
            if ($invoiceId > 0) {
                $existing = $this->repo->find($invoiceId);
                $check = $pdo->prepare('SELECT snapshot_hash FROM rebilling_receipts WHERE supplier_id=? AND invoice_id=? LIMIT 1');
                $check->execute([$sid,$invoiceId]);
                if (!$existing || !hash_equals((string) $check->fetchColumn(), RebillingPolicy::snapshot($existing))) {
                    $pdo->rollBack(); return Json::error($response, 'draft_changed', 'Koncept byl ručně změněn. Je nutná kontrola.', 409);
                }
                $existingItems = $existing['items'];
            } else {
                $data = $this->defaults->resolve(['client_id'=>$clientId,'invoice_type'=>'invoice','issue_date'=>date('Y-m-t', strtotime($from)),
                    'tax_date'=>date('Y-m-t', strtotime($from)),'currency'=>'CZK','prices_include_vat'=>0,'reverse_charge'=>0,
                    'auto_send_reminders'=>0,'discount_percent'=>0], forNewInvoice:true);
                $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
                $invoiceId = $this->repo->createDraft($data,(int) $user['id']);
            }
            $ratesQuery=$pdo->prepare("SELECT id,rate_percent FROM vat_rates WHERE country='CZ' AND is_reverse_charge=0
                AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?) ORDER BY is_default DESC,id");
            $ratesQuery->execute([$from,$from]);
            $rates=[];foreach($ratesQuery->fetchAll(\PDO::FETCH_ASSOC) as $rate)$rates[(int)$rate['id']]=(float)$rate['rate_percent'];
            $items = $existingItems;
            foreach ($body['lines'] as $line) {
                $rateId = array_search((float) $line['vatRate'], array_map('floatval',$rates), true);
                if ($rateId === false) throw new \InvalidArgumentException('Chybí sazba DPH.');
                $items[] = ['description'=>$line['description'],'quantity'=>$line['quantity'],'unit'=>'ks',
                    'unit_price_without_vat'=>$line['unitPriceCents']/100,'vat_rate_id'=>$rateId,'order_index'=>count($items)];
            }
            $header=$this->repo->find($invoiceId);
            if(!$header)throw new \RuntimeException('Koncept nebyl nalezen.');
            $header['items']=$items;
            $validation=InvoiceValidation::invoice($header,$this->repo->vatRateMap(),$this->repo->vatRateCountryMap(),'CZ');
            if($validation!==[])throw new \InvalidArgumentException('Koncept vyžaduje kontrolu: '.json_encode($validation,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            $this->repo->replaceItems($invoiceId,$items);
            $this->calculator->recompute($invoiceId);
            $numbers = $pdo->prepare('SELECT source_number FROM rebilling_receipts WHERE supplier_id=? AND invoice_id=? ORDER BY created_at,source_key');
            $numbers->execute([$sid,$invoiceId]);
            $note = implode(' + ',array_unique([...$numbers->fetchAll(\PDO::FETCH_COLUMN),$body['invoice_number']]));
            $pdo->prepare('UPDATE invoices SET note_below_items=? WHERE id=? AND supplier_id=? AND status=\'draft\'')->execute([$note,$invoiceId,$sid]);
            $stored = $this->repo->find($invoiceId);
            // Celý koncept musí stále splňovat KEG = dodaná záloha. Původní řádky chrání snapshot.
            $beer=0; $deposits=0;
            foreach ($stored['items'] as $item) {
                $q=(float)$item['quantity'] * ((float)$item['unit_price_without_vat']<0?-1:1);
                if ($item['description']==='CVAUD 11 - 50l KEG') $beer+=$q;
                if ($item['description']==='Záloha KEG 50l') $deposits+=$q;
            }
            if ($beer!==$deposits) throw new \InvalidArgumentException('Počet KEG neodpovídá zálohám výsledného konceptu.');
            $snapshot = RebillingPolicy::snapshot($stored);
            $ids = array_map(static fn(array $i):string=>(string)$i['id'],$stored['items']);
            $all = $pdo->prepare('SELECT source_key,start_index,line_count FROM rebilling_receipts WHERE supplier_id=? AND invoice_id=?');
            $all->execute([$sid,$invoiceId]);
            $update = $pdo->prepare('UPDATE rebilling_receipts SET line_ids=?,snapshot_hash=? WHERE supplier_id=? AND source_key=?');
            foreach ($all->fetchAll(\PDO::FETCH_ASSOC) as $r) $update->execute([json_encode(array_slice($ids,(int)$r['start_index'],(int)$r['line_count']),JSON_THROW_ON_ERROR),$snapshot,$sid,$r['source_key']]);
            $newIds = array_slice($ids,count($existingItems));
            $insert = $pdo->prepare('INSERT INTO rebilling_receipts(supplier_id,source_key,payload_hash,source_hash,source_number,invoice_id,line_ids,start_index,line_count,snapshot_hash) VALUES(?,?,?,?,?,?,?,?,?,?)');
            $insert->execute([$sid,$body['source_key'],$hash,$body['source_hash'],$body['invoice_number'],$invoiceId,json_encode($newIds,JSON_THROW_ON_ERROR),count($existingItems),count($body['lines']),$snapshot]);
            $actor=(array)$request->getAttribute(AuthMiddleware::ATTR_USER,[]);
            $this->logger->log('invoice.rebilling_draft_updated',(int)($actor['id']??0),'invoice',$invoiceId,
                ['source_key'=>$body['source_key'],'source_hash'=>$body['source_hash'],'api_token_id'=>$token['id']??null],null,null,$sid);
            $pdo->commit();
            return Json::ok($response,['receipt'=>['invoice_id'=>(string)$invoiceId,'line_ids'=>$newIds,'source_hash'=>$body['source_hash']], 'invoices'=>$this->invoices($sid,$clientId,$month),'mappings'=>$this->mappings($sid,$clientId,$month)]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof \InvalidArgumentException) return Json::error($response,'invalid_rebilling',$e->getMessage(),422);
            throw $e;
        } finally { $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
    }

    private function period(string $month): array { $from=$month.'-01';return [$from,date('Y-m-d',strtotime($from.' +1 month'))]; }
    private function receipt(array $r): array {return ['invoice_id'=>(string)$r['invoice_id'],'line_ids'=>json_decode($r['line_ids'],true,512,JSON_THROW_ON_ERROR),'source_hash'=>$r['source_hash']];}
    private function mappings(int $sid,int $clientId,string $month):array {
        [$from,$to]=$this->period($month);
        $q=$this->db->pdo()->prepare('SELECT r.source_key,r.invoice_id,r.line_ids,r.source_hash FROM invoices i JOIN rebilling_receipts r ON r.supplier_id=i.supplier_id AND r.invoice_id=i.id WHERE i.supplier_id=? AND i.client_id=? AND i.issue_date>=? AND i.issue_date<?');
        $q->execute([$sid,$clientId,$from,$to]);$result=[];
        foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $r)$result[]=['source_key'=>$r['source_key'],...$this->receipt($r)];
        return $result;
    }
    private function invoices(int $sid,int $clientId,string $month): array
    {
        [$from,$to]=$this->period($month);$pdo=$this->db->pdo();
        $q=$pdo->prepare('SELECT i.id,i.varsymbol AS number,i.status,i.client_id,i.issue_date,i.note_below_items,c.code AS currency
            FROM invoices i JOIN currencies c ON c.id=i.currency_id WHERE i.supplier_id=? AND i.client_id=? AND i.issue_date>=? AND i.issue_date<? ORDER BY i.id LIMIT 501');
        $q->execute([$sid,$clientId,$from,$to]);$invoices=$q->fetchAll(\PDO::FETCH_ASSOC);
        if(count($invoices)>500)throw new \RuntimeException('Příliš mnoho faktur pro jedno období.');
        if(!$invoices)return [];
        $q=$pdo->prepare('SELECT ii.invoice_id,ii.id,ii.description,ii.quantity,ii.unit_price_without_vat,ii.vat_rate_snapshot FROM invoices i JOIN invoice_items ii ON ii.invoice_id=i.id WHERE i.supplier_id=? AND i.client_id=? AND i.issue_date>=? AND i.issue_date<? ORDER BY ii.invoice_id,ii.order_index,ii.id');
        $q->execute([$sid,$clientId,$from,$to]);$groups=[];
        foreach($q->fetchAll(\PDO::FETCH_ASSOC) as $item){$id=$item['invoice_id'];unset($item['invoice_id']);$groups[$id][]=$item;}
        foreach($invoices as &$invoice){$invoice['id']=(string)$invoice['id'];$invoice['client_id']=(int)$invoice['client_id'];$invoice['items']=$groups[$invoice['id']]??[];}
        return $invoices;
    }
}
