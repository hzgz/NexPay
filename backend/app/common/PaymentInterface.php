<?php

declare(strict_types=1);

namespace app\common;

interface PaymentInterface
{
    public function submit(PaymentContext $ctx): array;
}
