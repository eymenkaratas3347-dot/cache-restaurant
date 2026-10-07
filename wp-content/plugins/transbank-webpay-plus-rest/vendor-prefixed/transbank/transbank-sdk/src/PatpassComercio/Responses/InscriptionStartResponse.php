<?php

/**
 * Class InscriptionStartResponse.
 *
 * @category
 */
namespace TransbankVendor\Transbank\PatpassComercio\Responses;

use TransbankVendor\Transbank\Utils\Utils;
class InscriptionStartResponse
{
    public string|null $token;
    public string|null $urlWebpay;
    /**
     * InscriptionStartResponse constructor.
     *
     * @param array $json
     */
    public function __construct(array $json)
    {
        $this->token = Utils::returnValueIfExists($json, 'token');
        $this->urlWebpay = Utils::returnValueIfExists($json, 'url');
    }
    /**
     * @return string|null
     */
    public function getToken(): string|null
    {
        return $this->token;
    }
    /**
     * @return string|null
     */
    public function getUrlWebpay(): string|null
    {
        return $this->urlWebpay;
    }
}
