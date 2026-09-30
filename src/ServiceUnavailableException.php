<?php

declare(strict_types=1);

namespace Xproapi;

/** 5xx: X or the API is temporarily unavailable. Safe to retry later. */
class ServiceUnavailableException extends XproapiException {}
