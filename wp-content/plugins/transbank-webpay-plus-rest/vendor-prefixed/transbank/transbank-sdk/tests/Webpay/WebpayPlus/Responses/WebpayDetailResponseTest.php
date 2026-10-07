<?php

namespace TransbankVendor;

use TransbankVendor\PHPUnit\Framework\TestCase;
use TransbankVendor\PHPUnit\Framework\Attributes\Test;
use TransbankVendor\Transbank\Webpay\WebpayPlus\Responses\TransactionDetail;
class WebpayDetailResponseTest extends TestCase
{
    private TransactionDetail $details;
    public function setUp(): void
    {
        $this->details = new TransactionDetail();
        $this->details->responseCode = -1;
        $this->details->status = 'AUTHORIZED';
        $this->details->balance = 100;
    }
    #[Test]
    public function it_checks_is_approved()
    {
        $this->assertFalse($this->details->isApproved());
        $this->details->responseCode = 0;
        $this->assertTrue($this->details->isApproved());
        $this->details->status = 'FAKE_STATUS';
        $this->assertFalse($this->details->isApproved());
    }
    #[Test]
    public function it_can_get_balance()
    {
        $this->assertEquals(100, $this->details->getBalance());
    }
}
\class_alias('TransbankVendor\WebpayDetailResponseTest', 'WebpayDetailResponseTest', \false);
