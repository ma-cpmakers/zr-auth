<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Validation\Validator;

/**
 * La `regola` di un errore di campo (sprint 25d, #1623), come la dice il backoffice: un codice stabile, non tradotto, di un
 * elenco chiuso (lo schema ErroreDiCampo del contratto). Qui è la copia di `App\Http\Problemi\Regola::dellaValidazione()`:
 * dalla regola di Laravel che ha rifiutato il valore, al codice.
 */
final class Regola
{
    public const OBBLIGATORIO = 'obbligatorio';

    public const TIPO = 'tipo';

    public const TROPPO_LUNGO = 'troppo_lungo';

    public const TROPPO_CORTO = 'troppo_corto';

    public const FUORI_INTERVALLO = 'fuori_intervallo';

    public const FORMATO = 'formato';

    public const VALORE_NON_AMMESSO = 'valore_non_ammesso';

    public const DUPLICATO = 'duplicato';

    public const CAMPO_ESTRANEO = 'campo_estraneo';

    public const CAMPO_VIETATO = 'campo_vietato';

    public const NON_TROVATO = 'non_trovato';

    public const NON_VALIDA = 'non_valida';

    private const DI_TIPO = ['String', 'Integer', 'Boolean', 'Array', 'List', 'Numeric', 'Decimal', 'Json'];

    private const DI_OBBLIGO = ['Required', 'RequiredWith', 'RequiredWithAll', 'RequiredWithout', 'RequiredWithoutAll', 'RequiredIf', 'RequiredUnless', 'Filled', 'Present'];

    private const DI_FORMATO = ['Email', 'Url', 'ActiveUrl', 'Regex', 'NotRegex', 'Ulid', 'Uuid', 'Date', 'DateFormat', 'Timezone', 'AlphaDash', 'AlphaNum', 'Alpha', 'Ascii', 'Base64', 'Cursore', 'Sequenza', 'SenzaCarattereNullo'];

    private const DI_VALORE = ['In', 'NotIn', 'Accepted', 'AcceptedIf', 'Declined', 'Same', 'Different', 'Confirmed'];

    private const DI_MISURA = ['Max', 'Min', 'Size', 'Between'];

    /**
     * La regola violata dal campo `$chiave` di un validatore che ha fallito (il suo primo rifiuto, nell'ordine delle regole).
     * Una closure non ha un nome che `failed()` sappia dire: chi la scrive la dichiara (`$dichiarate`, per attributo).
     *
     * @param  array<string, string>  $dichiarate
     */
    public static function dellaValidazione(Validator $validatore, string $chiave, array $dichiarate = []): string
    {
        $fallite = $validatore->failed()[$chiave] ?? [];
        $nome = array_key_first($fallite);

        if ($nome === null) {
            return $dichiarate[$chiave] ?? self::NON_VALIDA;
        }

        $nome = class_basename((string) $nome);

        return match (true) {
            $nome === 'ClosureValidationRule' => $dichiarate[$chiave] ?? self::NON_VALIDA,
            in_array($nome, self::DI_OBBLIGO, true) => self::OBBLIGATORIO,
            in_array($nome, self::DI_TIPO, true) => self::TIPO,
            in_array($nome, self::DI_FORMATO, true) => self::FORMATO,
            in_array($nome, self::DI_VALORE, true) => self::VALORE_NON_AMMESSO,
            in_array($nome, self::DI_MISURA, true) => self::dellaMisura($validatore, $chiave, $nome),
            $nome === 'MassimoDiByte' => self::TROPPO_LUNGO,
            $nome === 'Distinct' => self::DUPLICATO,
            in_array($nome, ['Prohibited', 'Prohibits', 'Missing'], true) => self::CAMPO_VIETATO,
            $nome === 'Exists' => self::NON_TROVATO,
            default => self::NON_VALIDA,
        };
    }

    /** Max, Min, Size e Between misurano un testo, un numero o un elenco, secondo le altre regole del campo. */
    private static function dellaMisura(Validator $validatore, string $chiave, string $nome): string
    {
        $regole = (array) ($validatore->getRules()[$chiave] ?? []);
        $nomi = array_map(fn ($regola) => is_string($regola) ? strtolower(explode(':', $regola)[0]) : '', $regole);
        $misuraLaQuantita = array_intersect(['integer', 'numeric', 'decimal', 'array', 'list'], $nomi) !== [];

        if ($misuraLaQuantita || $nome === 'Between' || $nome === 'Size') {
            return self::FUORI_INTERVALLO;
        }

        return $nome === 'Min' ? self::TROPPO_CORTO : self::TROPPO_LUNGO;
    }
}
