<?php

namespace TransbankVendor;

use TransbankVendor\PHPUnit\Framework\TestCase;
use TransbankVendor\PHPUnit\Framework\Attributes\Test;
use TransbankVendor\Transbank\PatpassComercio\Responses\InscriptionStartResponse;
class InscriptionStartResponseTest extends TestCase
{
    protected $inscriptionStartResponse;
    public function setUp(): void
    {
        $json = ['token' => 'testToken', 'url' => 'testUrl'];
        $this->inscriptionStartResponse = new InscriptionStartResponse($json);
    }
    #[Test]
    public function it_can_set_and_get_token()
    {
        $this->assertSame('testToken', $this->inscriptionStartResponse->getToken());
    }
    #[Test]
    public function it_can_set_and_get_url_webpay()
    {
        $this->assertSame('testUrl', $this->inscriptionStartResponse->getUrlWebpay());
    }
}
\class_alias('TransbankVendor\InscriptionStartResponseTest', 'InscriptionStartResponseTest', \false);
