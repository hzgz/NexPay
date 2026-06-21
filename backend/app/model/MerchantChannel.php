<?php

namespace app\model;

class MerchantChannel extends BaseModel
{
    protected $name = 'merchant_channels';

    protected $json = ['config'];

    protected $jsonAssoc = true;
}
