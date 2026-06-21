<?php

namespace app\model;

class ChannelType extends BaseModel
{
    protected $name = 'channel_types';

    protected $json = ['config_schema'];

    protected $jsonAssoc = true;
}
