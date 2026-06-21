<?php

namespace app\model;

class AdminUser extends BaseModel
{
    protected $name = 'admin_users';

    protected $hidden = ['password_hash'];
}
