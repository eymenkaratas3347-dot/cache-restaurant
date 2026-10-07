<?php

namespace TransbankVendor;

use TransbankVendor\PHPUnit\Framework\TestCase;
use TransbankVendor\PHPUnit\Framework\Attributes\Test;
use TransbankVendor\Transbank\PatpassComercio\Options;
class PatpassComercioOptionsTest extends TestCase
{
    #[Test]
    public function it_returns_the_right_headers_based_on_configuration()
    {
        $options = new Options('ApiKey', 'CommerceCode', 'TEST');
        $this->assertSame($options->getHeaders(), ['commercecode' => 'CommerceCode', 'Authorization' => 'ApiKey']);
    }
}
\class_alias('TransbankVendor\PatpassComercioOptionsTest', 'PatpassComercioOptionsTest', \false);
