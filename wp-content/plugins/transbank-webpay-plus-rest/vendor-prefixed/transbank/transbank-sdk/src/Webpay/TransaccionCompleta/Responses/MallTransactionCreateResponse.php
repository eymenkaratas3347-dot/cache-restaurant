<?php

namespace TransbankVendor\Transbank\Webpay\TransaccionCompleta\Responses;

use TransbankVendor\Transbank\Utils\Utils;
class MallTransactionCreateResponse
{
    public string|null $token;
    public function __construct(array $json)
    {
        $this->token = Utils::returnValueIfExists($json, 'token');
    }
    /**
     * @return string|null
     */
    public function getToken(): string|null
    {
        return $this->token;
    }
}
