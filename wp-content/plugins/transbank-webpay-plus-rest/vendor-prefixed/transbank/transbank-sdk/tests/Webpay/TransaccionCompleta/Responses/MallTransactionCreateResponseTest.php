<?php

namespace TransbankVendor;

use TransbankVendor\PHPUnit\Framework\TestCase;
use TransbankVendor\Transbank\Webpay\TransaccionCompleta\Responses\MallTransactionCreateResponse;
class MallTransactionCreateResponseTest extends TestCase
{
    public function testGetToken()
    {
        $token = '123456';
        $response = new MallTransactionCreateResponse(['token' => $token]);
        $this->assertEquals($token, $response->getToken());
    }
    public function testTokenProperty()
    {
        $response = new MallTransactionCreateResponse(['token' => '123456']);
        $this->assertEquals('123456', $response->token);
    }
}
\class_alias('TransbankVendor\MallTransactionCreateResponseTest', 'MallTransactionCreateResponseTest', \false);
