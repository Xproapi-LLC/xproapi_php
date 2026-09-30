<?php

declare(strict_types=1);

namespace Xproapi;

/** 403: the account can't use the API, or the X account is protected. */
class PermissionDeniedException extends XproapiException {}
