<?php

declare(strict_types=1);

namespace Xproapi;

/** 402: the balance can't cover the request. Nothing was charged. */
class InsufficientCreditsException extends XproapiException {}
