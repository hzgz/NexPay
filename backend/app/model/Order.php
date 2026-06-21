<?php

namespace app\model;

class Order extends BaseModel
{
    protected $name = 'orders';

    protected $json = ['request_payload', 'notify_payload'];

    protected $jsonAssoc = true;
}
