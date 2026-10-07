<?php

namespace TransbankVendor;

use TransbankVendor\PHPUnit\Framework\TestCase;
use TransbankVendor\PHPUnit\Framework\Attributes\Test;
use TransbankVendor\Transbank\PatpassComercio\Options;
use TransbankVendor\Transbank\Utils\HttpClientRequestService;
use TransbankVendor\Transbank\Webpay\Oneclick;
use TransbankVendor\Transbank\Webpay\Oneclick\Exceptions\MallQueryBinException;
use TransbankVendor\Transbank\Webpay\Oneclick\MallBinInfo;
use TransbankVendor\Transbank\Webpay\Oneclick\Responses\MallQueryBinResponse;
class TransbankBinInfoTest extends TestCase
{
    #[Test]
    public function it_query_a_bin()
    {
        $requestServiceMock = $this->createMock(HttpClientRequestService::class);
        $requestServiceMock->expects($this->once())->method('request')->willReturn(["bin_issuer" => "BANCO TEST", "bin_payment_type" => "Crédito", "bin_brand" => "VISA"]);
        $mallBinInfo = new MallBinInfo(new Options('apiKey', 'commerce', Options::ENVIRONMENT_INTEGRATION), $requestServiceMock);
        $response = $mallBinInfo->queryBin('fakeTbkUser');
        $this->assertInstanceOf(MallQueryBinResponse::class, $response);
        $this->assertEquals('BANCO TEST', $response->getBinIssuer());
        $this->assertEquals('Crédito', $response->getBinPaymentType());
        $this->assertEquals('VISA', $response->getBinBrand());
    }
    #[Test]
    public function it_fails_querying_a_bin_with_empty_data()
    {
        $this->expectException(MallQueryBinException::class);
        $this->expectExceptionMessage('tbk_user is required!');
        MallBinInfo::buildForIntegration(Oneclick::INTEGRATION_API_KEY, Oneclick::INTEGRATION_COMMERCE_CODE)->queryBin('');
    }
}
\class_alias('TransbankVendor\TransbankBinInfoTest', 'TransbankBinInfoTest', \false);
