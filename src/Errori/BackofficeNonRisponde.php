<?php

namespace Zeiras\Auth\Errori;

use RuntimeException;

/**
 * Il backoffice non ha dato una risposta da usare: non risponde entro il timeout, la connessione cade, un 5xx, una
 * risposta senza JSON o senza la forma attesa. Non è mai una lista vuota: chi chiama decide cosa mostrare.
 */
final class BackofficeNonRisponde extends RuntimeException {}
