<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Integration;
use MyInvoice\Bootstrap;
use MyInvoice\Action\Invoice\RebillingAction;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

final class RebillingDraftsTest extends TestCase
{
    private \PDO $pdo; private RebillingAction $action; private int $sid; private int $client; private int $user;
    private string $prefix; private array $env=[];
    protected function setUp():void {
        $c=Bootstrap::buildApp()->getContainer();$this->pdo=$c->get(Connection::class)->pdo();$this->action=$c->get(RebillingAction::class);
        $this->prefix='synthetic:'.bin2hex(random_bytes(8));
        // Izolovaný syntetický tenant; stejně jako setup dočasně řeší cyklickou vazbu měny.
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $this->pdo->exec("INSERT INTO supplier(company_name,street,city,zip,country_id,email,default_currency_id,default_vat_rate_id)
                VALUES('Syntetická integrace','Test 1','Praha','11000',(SELECT id FROM countries WHERE iso2='CZ' LIMIT 1),'rebilling@example.test',0,(SELECT id FROM vat_rates WHERE code='CZ-21' LIMIT 1))");
            $this->sid=(int)$this->pdo->lastInsertId();
            $this->pdo->prepare("INSERT INTO currencies(supplier_id,code,label,symbol,name_cs,name_en,decimals,is_active,is_default) VALUES(?,'CZK','CZK','Kč','Koruna','Koruna',2,1,1)")->execute([$this->sid]);
            $this->pdo->prepare('UPDATE supplier SET default_currency_id=? WHERE id=?')->execute([(int)$this->pdo->lastInsertId(),$this->sid]);
        } finally {$this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
        $this->pdo->prepare("INSERT INTO users(email,password_hash,name,role,locale,is_active) VALUES(?,'synthetic-unused-password','Syntetický uživatel','admin','cs',1)")->execute([$this->prefix.'@example.test']);
        $this->user=(int)$this->pdo->lastInsertId();
        $s=$this->pdo->prepare("INSERT INTO clients(supplier_id,company_name,street,city,zip,main_email,country_id,currency_default_id)
            VALUES(?,'Syntetická přefakturace','Test 1','Praha','11000','rebilling@example.test',(SELECT id FROM countries WHERE iso2='CZ' LIMIT 1),
            (SELECT id FROM currencies WHERE supplier_id=? AND code='CZK' LIMIT 1))");$s->execute([$this->sid,$this->sid]);$this->client=(int)$this->pdo->lastInsertId();
        foreach(['REBILLING_CLIENT_ID'=>$this->client,'REBILLING_SUPPLIER_ID'=>$this->sid] as $k=>$v){$this->env[$k]=getenv($k);putenv($k.'='.$v);}
    }
    protected function tearDown():void {
        if($this->pdo->inTransaction())$this->pdo->rollBack();
        $this->pdo->prepare('DELETE FROM rebilling_receipts WHERE supplier_id=? AND source_key LIKE ?')->execute([$this->sid,$this->prefix.'%']);
        $this->pdo->prepare('DELETE FROM invoices WHERE supplier_id=? AND client_id=?')->execute([$this->sid,$this->client]);
        $this->pdo->prepare('DELETE FROM clients WHERE supplier_id=? AND id=?')->execute([$this->sid,$this->client]);
        $this->pdo->prepare('DELETE FROM users WHERE id=?')->execute([$this->user]);
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {$this->pdo->prepare('DELETE FROM currencies WHERE supplier_id=?')->execute([$this->sid]);}finally{$this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
        $this->pdo->prepare('DELETE FROM supplier WHERE id=?')->execute([$this->sid]);
        foreach($this->env as $k=>$v)putenv($v===false?$k:$k.'='.$v);
    }
    private function body(string $suffix='1'):array {return ['client_id'=>$this->client,'month'=>'2095-06','mode'=>'monthly','source_key'=>$this->prefix.':'.$suffix,'source_hash'=>str_repeat('a',64),'invoice_number'=>'SYN-'.$suffix,
        'lines'=>[['kind'=>'beer_keg','description'=>'CVAUD 11 - 50l KEG','quantity'=>3,'unitPriceCents'=>250000,'vatRate'=>21],
        ['kind'=>'keg_delivery','description'=>'Záloha KEG 50l','quantity'=>3,'unitPriceCents'=>150000,'vatRate'=>0],
        ['kind'=>'keg_return','description'=>'Záloha KEG 50l (vrácené)','quantity'=>2,'unitPriceCents'=>-150000,'vatRate'=>0]]];}
    private function call(array $body,string $method='POST',string $scope='rebilling_drafts'):array {
        $r=(new ServerRequestFactory())->createServerRequest($method,'/api/invoices/rebilling')->withQueryParams(['month'=>'2095-06'])
            ->withHeader('Idempotency-Key',$body['source_key'])->withParsedBody($body)
            ->withAttribute(AuthMiddleware::ATTR_API_TOKEN,['scope'=>$scope,'supplier_id'=>$this->sid])
            ->withAttribute(AuthMiddleware::ATTR_USER,['id'=>$this->user])->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID,$this->sid);
        $response=($this->action)($r,(new ResponseFactory())->createResponse());return [$response->getStatusCode(),json_decode((string)$response->getBody(),true,512,JSON_THROW_ON_ERROR)];
    }
    public function testReplayAppendAndIssuedDraftRollover():void {
        [$status,$a]=$this->call($this->body());self::assertSame(200,$status,json_encode($a));$id=$a['receipt']['invoice_id'];
        self::assertSame('draft',$a['invoices'][0]['status']);self::assertSame(3,count($a['receipt']['line_ids']));
        [$status,$again]=$this->call($this->body());self::assertSame(200,$status);self::assertSame($a['receipt'],$again['receipt']);
        [$status,$second]=$this->call($this->body('2'));self::assertSame(200,$status,json_encode($second));self::assertSame($id,$second['receipt']['invoice_id']);
        self::assertCount(6,$second['invoices'][0]['items']);
        self::assertSame('SYN-1 + SYN-2',$this->pdo->query('SELECT note_below_items FROM invoices WHERE id='.(int)$id)->fetchColumn());
        $this->pdo->exec("UPDATE invoices SET status='issued' WHERE id=".(int)$id);
        [$status,$third]=$this->call($this->body('3'));self::assertSame(200,$status,json_encode($third));self::assertNotSame($id,$third['receipt']['invoice_id']);
        [$status,$replay]=$this->call($this->body());self::assertSame(200,$status);self::assertSame($id,$replay['receipt']['invoice_id']);
        self::assertCount(2,$third['invoices']);
    }
    public function testChangedSourceAndManualEditAreConflicts():void {
        [, $a]=$this->call($this->body());$id=(int)$a['receipt']['invoice_id'];
        $changed=$this->body();$changed['lines'][0]['unitPriceCents']++;self::assertSame(409,$this->call($changed)[0]);
        $this->pdo->exec('UPDATE invoice_items SET quantity=99 WHERE invoice_id='.$id.' ORDER BY id LIMIT 1');
        self::assertSame(409,$this->call($this->body('2'))[0]);
        self::assertSame(3,(int)$this->pdo->query('SELECT count(*) FROM invoice_items WHERE invoice_id='.$id)->fetchColumn());
    }
    public function testInvalidRatioAndGeneralTokenCannotWrite():void {
        $b=$this->body();$b['lines'][1]['quantity']=2;self::assertSame(422,$this->call($b)[0]);
        self::assertSame(403,$this->call($this->body(),'POST','read_write')[0]);
        self::assertSame(0,(int)$this->pdo->query('SELECT count(*) FROM invoices WHERE client_id='.$this->client)->fetchColumn());
    }
    public function testNetNegativeResultRequiresManualCreditNoteWithoutLeavingDraft():void {
        $b=$this->body();$b['lines']=array_slice($b['lines'],0,2);foreach($b['lines'] as &$l)$l['unitPriceCents']=-$l['unitPriceCents'];
        self::assertSame(422,$this->call($b)[0]);
        self::assertSame(0,(int)$this->pdo->query('SELECT count(*) FROM invoices WHERE client_id='.$this->client)->fetchColumn());
    }
}
