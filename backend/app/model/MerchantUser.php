<?php

namespace app\model;

class MerchantUser extends BaseModel
{
    protected $name = 'merchant_users';

    protected $hidden = ['password_hash'];
}
