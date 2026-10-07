<?php

namespace TransbankVendor\Transbank\Webpay\WebpayPlus\Exceptions;

use TransbankVendor\Transbank\Webpay\Exceptions\WebpayRequestException;
class TransactionCommitException extends WebpayRequestException
{
    protected static string $defaultMessage = 'The transaction could not be confirmed';
}
