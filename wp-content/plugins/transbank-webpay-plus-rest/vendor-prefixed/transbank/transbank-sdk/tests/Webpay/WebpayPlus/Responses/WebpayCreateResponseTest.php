<?php

namespace TransbankVendor;

use TransbankVendor\PHPUnit\Framework\TestCase;
use TransbankVendor\PHPUnit\Framework\Attributes\Test;
use TransbankVendor\Transbank\Webpay\WebpayPlus\Responses\TransactionCreateResponse;
class WebpayCreateResponseTest extends TestCase
{
    private TransactionCreateResponse $create;
    public function setUp(): void
    {
        $data = ['token' => 'fakeToken', 'url' => 'https://www.urlpruebas.cl'];
        $this->create = new TransactionCreateResponse($data);
    }
    #[Test]
    public function it_can_set_from_array()
    {
        $this->create->fromJSON(['token' => 'newFakeToken', 'url' => 'https://newurl.cl']);
        $this->assertEquals('newFakeToken', $this->create->getToken());
        $this->assertEquals('https://newurl.cl', $this->create->getUrl());
    }
}
\class_alias('TransbankVendor\WebpayCreateResponseTest', 'WebpayCreateResponseTest', \false);
