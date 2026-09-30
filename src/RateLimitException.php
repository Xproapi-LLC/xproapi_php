<?php

declare(strict_types=1);

namespace Xproapi;

/** 429: too many requests. $retryAfter says how many seconds to wait. */
class RateLimitException extends XproapiException {}
