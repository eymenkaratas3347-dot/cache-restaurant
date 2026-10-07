<?php

namespace TransbankVendor;

use TransbankVendor\PHPUnit\Framework\TestCase;
use TransbankVendor\PHPUnit\Framework\Attributes\Test;
use TransbankVendor\Transbank\Webpay\TransaccionCompleta\Responses\TransactionCreateResponse;
class TransactionCompletareateResponseTest extends TestCase
{
    #[Test]
    public function it_can_get_token()
    {
        $json = ['token' => 'testToken'];
        $response = new TransactionCreateResponse($json);
        $this->assertSame('testToken', $response->getToken());
    }
    #[Test]
    public function it_returns_null_when_key_does_not_exist_in_json()
    {
        $json = [];
        $response = new TransactionCreateResponse($json);
        $this->assertNull($response->getToken());
    }
}
\class_alias('TransbankVendor\TransactionCompletareateResponseTest', 'TransactionCompletareateResponseTest', \false);
