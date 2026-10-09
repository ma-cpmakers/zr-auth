<?php

namespace Zeiras\Auth\Eventi;

/**
 * La firma di Standard Webhooks, come la mette la consegna del backoffice: `v1,` e il base64 dell'HMAC-SHA256 di
 * `id.timestamp.corpo` con la chiave. La chiave è il segreto senza `whsec_`, decodificato.
 */
final class Firma
{
    /** Quanti byte può avere la chiave decodificata (come lo accetta il backoffice per un'iscrizione). */
    private const BYTE_MINIMI = 24;

    private const BYTE_MASSIMI = 64;

    /** La chiave di un segreto `whsec_<base64>` da 24 a 64 byte, o null: un segreto assente o fatto male non firma niente. */
    public static function chiave(mixed $segreto): ?string
    {
        if (! is_string($segreto) || ! str_starts_with($segreto, 'whsec_')) {
            return null;
        }

        $chiave = base64_decode(substr($segreto, 6), true);

        return $chiave !== false && strlen($chiave) >= self::BYTE_MINIMI && strlen($chiave) <= self::BYTE_MASSIMI ? $chiave : null;
    }

    public static function calcola(string $chiave, string $id, int $timestamp, string $corpo): string
    {
        return 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$corpo}", $chiave, true));
    }

    /**
     * Vale se una delle firme `v1,…` dell'header (separate da spazi) è quella giusta. Un'altra versione (`v2,…`, `v1a,…`)
     * non conta: né vale né fa eccezione. Il confronto è a tempo costante e passa da tutte le firme, senza fermarsi alla
     * prima che combacia.
     */
    public static function verifica(string $chiave, string $id, int $timestamp, string $corpo, string $intestazione): bool
    {
        $attesa = self::calcola($chiave, $id, $timestamp, $corpo);
        $valida = false;

        foreach (preg_split('/ +/', $intestazione, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $firma) {
            if (str_starts_with($firma, 'v1,') && hash_equals($attesa, $firma)) {
                $valida = true;
            }
        }

        return $valida;
    }
}
