<?php

namespace app\model;

class CallbackQueue extends BaseModel
{
    protected $name = 'callback_queue';

    protected $json = ['payload'];

    protected $jsonAssoc = true;
}
