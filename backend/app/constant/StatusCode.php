<?php

namespace app\constant;

final class StatusCode
{
    public const SUCCESS = 0;
    public const BAD_REQUEST = 400;
    public const UNAUTHORIZED = 401;
    public const FORBIDDEN = 403;
    public const NOT_FOUND = 404;
    public const TOO_MANY_REQUESTS = 429;
    public const VALIDATION_ERROR = 422;
    public const BUSINESS_ERROR = 1000;
}
