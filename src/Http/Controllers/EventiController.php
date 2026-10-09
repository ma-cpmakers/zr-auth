<?php

namespace Zeiras\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Zeiras\Auth\Eventi\EventoDelBackoffice;
use Zeiras\Auth\Eventi\Firma;

/**
 * Il ricevitore degli eventi (`POST zr-auth.eventi`, sul percorso che il modulo sceglie): il backoffice consegna un evento
 * firmato con Standard Webhooks. Ogni rifiuto per la firma, l'istante o gli header è lo stesso 401, senza un motivo: chi
 * non ha il segreto non impara quale parte ha sbagliato. L'evento verificato arriva al modulo come EventoDelBackoffice.
 * Il `webhook-id` è la chiave dei doppioni (cache del modulo, con un tempo): un evento già visto è un 204 senza passarlo
 * di nuovo. Il tempo non è mai meno del doppio della tolleranza: una firma vale fino a `tolleranza` secondi dopo un istante
 * che può stare `tolleranza` secondi nel futuro, e un doppione non deve poter rientrare prima che la firma scada. Nei log di questo pacchetto mai il corpo, il segreto o l'header della firma.
 */
final class EventiController
{
    private const ID = '/^[A-Za-z0-9_-]{1,128}$/';

    public function __invoke(Request $richiesta): Response|JsonResponse
    {
        // Il corpo come è arrivato: la firma è sui suoi byte, e un corpo ricodificato non li ha più uguali.
        $corpo = $richiesta->getContent();
        $id = $richiesta->headers->get('webhook-id');
        $istante = $richiesta->headers->get('webhook-timestamp');
        $firma = $richiesta->headers->get('webhook-signature');
        $chiave = Firma::chiave(config('zr-auth.eventi.segreto'));

        if ($chiave === null || ! is_string($id) || preg_match(self::ID, $id) !== 1
            || ! is_string($istante) || preg_match('/^[0-9]{1,12}$/', $istante) !== 1
            || ! is_string($firma) || $firma === ''
            || abs(now()->getTimestamp() - (int) $istante) > self::tolleranza()
            || ! Firma::verifica($chiave, $id, (int) $istante, $corpo, $firma)) {
            return self::problema(401);
        }

        $evento = self::evento($corpo);

        if ($evento === null) {
            return self::problema(400);
        }

        // Il marcatore si scrive solo dopo la firma e la forma: una firma sbagliata, o un corpo storto, non brucia l'id.
        $marcatore = 'zr-auth:evento:'.$id;

        if (! Cache::add($marcatore, 1, self::doppioni())) {
            return response()->noContent();
        }

        try {
            event($evento);
        } catch (Throwable $errore) {
            // Il modulo ha avuto un guasto: il marcatore si toglie, così il tentativo dopo riprova e l'evento non si perde.
            Cache::forget($marcatore);

            throw $errore;
        }

        return response()->noContent();
    }

    /** L'evento, se il corpo è un oggetto JSON con `id` e `type` stringhe e `data` oggetto. */
    private static function evento(string $corpo): ?EventoDelBackoffice
    {
        $oggetto = json_decode($corpo);
        $decodificato = json_decode($corpo, true);

        if (! is_object($oggetto) || ! is_array($decodificato)
            || ! is_string($decodificato['id'] ?? null) || ! is_string($decodificato['type'] ?? null)
            || ! is_object($oggetto->data ?? null) || ! is_array($decodificato['data'])) {
            return null;
        }

        return new EventoDelBackoffice(
            id: $decodificato['id'],
            type: $decodificato['type'],
            subject: is_string($decodificato['subject'] ?? null) ? $decodificato['subject'] : null,
            sequence: is_string($decodificato['sequence'] ?? null) ? $decodificato['sequence'] : null,
            data: $decodificato['data'],
            time: is_string($decodificato['time'] ?? null) ? $decodificato['time'] : null,
            corpo: $decodificato,
        );
    }

    private static function tolleranza(): int
    {
        $secondi = config('zr-auth.eventi.tolleranza');

        return is_int($secondi) && $secondi >= 0 ? $secondi : 300;
    }

    /** Quanto si ricorda un `webhook-id`: `eventi.doppioni`, e mai meno del doppio della tolleranza (più un secondo). */
    private static function doppioni(): int
    {
        $secondi = config('zr-auth.eventi.doppioni');
        $secondi = is_int($secondi) && $secondi > 0 ? $secondi : 300;

        return max($secondi, 2 * self::tolleranza() + 1);
    }

    /** Lo stesso corpo per ogni rifiuto di una stessa sorta: `{"status":401}`, un problem details senza altro. */
    private static function problema(int $stato): JsonResponse
    {
        return response()->json(['status' => $stato], $stato, ['Content-Type' => 'application/problem+json']);
    }
}
