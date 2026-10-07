<?php

namespace TransbankVendor\Transbank\Webpay\WebpayPlus\Exceptions;

use TransbankVendor\Transbank\Webpay\Exceptions\WebpayRequestException;
class TransactionRefundException extends WebpayRequestException
{
    protected static string $defaultMessage = 'The transaction could not be refunded.';
}
