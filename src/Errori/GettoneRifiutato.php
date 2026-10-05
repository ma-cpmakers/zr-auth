<?php

namespace Zeiras\Auth\Errori;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Zeiras\Auth\Http\Middleware\ConGettone;
use Zeiras\Auth\Sessione;

/**
 * Il backoffice non accetta più il gettone (401: scaduto, revocato con l'uscita, la persona tolta dal workspace), o la
 * sessione non ne ha uno. Non si riprova: si chiude la sessione e si rimanda all'ingresso (spec S01, «Il gettone»).
 */
final class GettoneRifiutato extends RuntimeException
{
    public function __construct(public readonly ?ErroreApi $errore = null)
    {
        parent::__construct('Il gettone non vale: si rifà l\'ingresso.');
    }

    /** È il percorso normale di un gettone scaduto o revocato: niente nel log. */
    public function report(): void {}

    public function render(Request $richiesta): Response
    {
        Sessione::chiudi();

        return ConGettone::versoLIngresso($richiesta);
    }
}
