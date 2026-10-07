<?php

namespace TransbankVendor\Transbank\Webpay\Oneclick;

use TransbankVendor\Transbank\Utils\InteractsWithWebpayApi;
use TransbankVendor\Transbank\Webpay\Exceptions\WebpayRequestException;
use TransbankVendor\Transbank\Webpay\Oneclick\Responses\MallQueryBinResponse;
use TransbankVendor\Transbank\Webpay\Oneclick\Exceptions\MallQueryBinException;
class MallBinInfo
{
    use InteractsWithWebpayApi;
    const QUERY_BIN_ENDPOINT = 'rswebpaytransaction/api/oneclick/v1.2/bin_info';
    /**
     * Get the BIN information for a given tbkUser.
     *
     * @param string $tbkUser
     *
     * @return MallQueryBinResponse
     *
     * @throws MallQueryBinException
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function queryBin(string $tbkUser): MallQueryBinResponse
    {
        $payload = ['tbk_user' => $tbkUser];
        try {
            $response = $this->sendRequest('POST', static::QUERY_BIN_ENDPOINT, $payload);
        } catch (WebpayRequestException $exception) {
            throw new MallQueryBinException($exception->getMessage(), $exception->getTransbankErrorMessage(), $exception->getHttpCode(), $exception->getFailedRequest(), $exception);
        }
        return new MallQueryBinResponse($response);
    }
}
