<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Contracts\Translation\Translator;

/**
 * Il nome di un campo come lo scrive il `detail` di un errore (#1623): in parole, nella lingua della richiesta. La copia di
 * `App\Http\Problemi\NomiDeiCampi` del backoffice: `validation.attributes` (con `*` al posto di un indice), e senza una
 * voce l'ultima parte del nome che non è un indice, con gli spazi al posto dei trattini bassi.
 */
final class NomiDeiCampi
{
    public static function di(string $attributo, Translator $traduttore): string
    {
        $voci = $traduttore->get('validation.attributes');
        $voci = is_array($voci) ? $voci : [];
        $generico = (string) preg_replace('/(^|\.)\d+(?=\.|$)/', '$1*', $attributo);

        foreach ([$attributo, $generico] as $chiave) {
            if (isset($voci[$chiave]) && is_string($voci[$chiave])) {
                return $voci[$chiave];
            }
        }

        $parti = array_values(array_filter(explode('.', $attributo), fn (string $parte) => ! ctype_digit($parte) && $parte !== '*'));

        return str_replace('_', ' ', $parti === [] ? $attributo : $parti[array_key_last($parti)]);
    }
}
