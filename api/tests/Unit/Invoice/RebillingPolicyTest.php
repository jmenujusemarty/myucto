<?php
declare(strict_types=1);
namespace MyInvoice\Tests\Unit\Invoice;
use MyInvoice\Service\Invoice\RebillingPolicy;
use PHPUnit\Framework\TestCase;
final class RebillingPolicyTest extends TestCase
{
    private function body():array {return ['client_id'=>1,'month'=>'2095-06','mode'=>'monthly','source_key'=>'synthetic:001','source_hash'=>str_repeat('a',64),'invoice_number'=>'SYN-001',
        'lines'=>[['kind'=>'beer_keg','description'=>'CVAUD 11 - 50l KEG','quantity'=>3,'unitPriceCents'=>250000,'vatRate'=>21],
            ['kind'=>'keg_delivery','description'=>'Záloha KEG 50l','quantity'=>3,'unitPriceCents'=>150000,'vatRate'=>0],
            ['kind'=>'keg_return','description'=>'Záloha KEG 50l (vrácené)','quantity'=>4,'unitPriceCents'=>-150000,'vatRate'=>0]]];}
    public function testValidDeliveryAndSeparateReturns():void {RebillingPolicy::validate($this->body());$this->addToAssertionCount(1);}
    public function testMismatchBlocksSave():void {$b=$this->body();$b['lines'][1]['quantity']=2;$this->expectException(\InvalidArgumentException::class);RebillingPolicy::validate($b);}
    public function testDepositVatBlocksSave():void {$b=$this->body();$b['lines'][1]['vatRate']=21;$this->expectException(\InvalidArgumentException::class);RebillingPolicy::validate($b);}
    public function testPositiveRefundBlocksSave():void {$b=$this->body();$b['lines'][2]['unitPriceCents']=150000;$this->expectException(\InvalidArgumentException::class);RebillingPolicy::validate($b);}
    public function testCannotRequestIssue():void {$b=$this->body();$b['status']='issued';$this->expectException(\InvalidArgumentException::class);RebillingPolicy::validate($b);}
    public function testCorrectionReversesBeerAndDepositTogether():void {$b=$this->body();$b['lines']=array_slice($b['lines'],0,2);foreach($b['lines'] as &$l)$l['unitPriceCents']=-$l['unitPriceCents'];RebillingPolicy::validate($b);$this->addToAssertionCount(1);}
}
