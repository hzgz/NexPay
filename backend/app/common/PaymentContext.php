<?php

declare(strict_types=1);

namespace app\common;

class PaymentContext
{
    public function __construct(
        public array $order,
        public string $ordername = '',
        public string $method = '',
        public bool $isMobile = false,
        public string $mdevice = '',
        public string $siteurl = '',
        public string $clientip = '',
        public array $query = [],
        public array $form = [],
        public array $runtime = [],
    ) {
    }
}
