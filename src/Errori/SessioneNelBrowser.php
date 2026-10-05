<?php

namespace Zeiras\Auth\Errori;

use LogicException;

/** La sessione del frontend sta nel cookie (`SESSION_DRIVER=cookie`): il gettone finirebbe nel browser. */
final class SessioneNelBrowser extends LogicException
{
    public function __construct()
    {
        parent::__construct('zr-auth non tiene il gettone in una sessione nel cookie: il gettone finirebbe nel browser. Usa una sessione lato server (Redis).');
    }
}
