<?php

namespace app\model;

use think\Model;

class BaseModel extends Model
{
    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'created_at';

    protected $updateTime = 'updated_at';

    protected $dateFormat = 'Y-m-d H:i:s';
}
