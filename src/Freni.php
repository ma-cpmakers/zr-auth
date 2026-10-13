<?php

namespace Zeiras\Auth;

use Illuminate\Http\Client\Response;

/**
 * I freni di una risposta del backoffice, per nome (#1911): li legge Api da `RateLimit-Policy` e `RateLimit` (draft IETF
 * httpapi-ratelimit-headers, liste di Structured Fields, RFC 8941). Un freno nasce da una politica: un residuo senza la sua
 * politica non dice di che freno è, e si ignora. Il parser non lancia mai: un elemento che non legge (una sintassi rotta, un
 * nome vuoto, una politica senza `q`, un parametro `q`, `w`, `r` o `t` che non è un intero da 0 a 15 cifre) si ignora, e così
 * un nome già visto. I parametri che il draft non nomina qui (`qu`, `pk`) si ignorano.
 *
 *     $api = Api::workspace();
 *     $api->post('/v1/crm/persone/importazioni', ['prova' => true, 'righe' => $righe]);
 *     $api->freni()?->freno('scrittura_persona')?->r;      // il residuo, o null se non si sa
 */
final class Freni
{
    /** @param  array<string, Freno>  $freni */
    private function __construct(private readonly array $freni) {}

    /** I freni della risposta, o `null` se non ne dice nessuno: nessun freno noto, che non è un residuo zero. */
    public static function daRisposta(Response $risposta): ?self
    {
        $residui = self::lista($risposta->header('RateLimit'), ['r', 't'], 'r');
        $freni = [];

        foreach (self::lista($risposta->header('RateLimit-Policy'), ['q', 'w'], 'q') as $nome => $politica) {
            $freni[$nome] = new Freno($nome, $politica['q'], $politica['w'] ?? null, $residui[$nome]['r'] ?? null, $residui[$nome]['t'] ?? null);
        }

        return $freni === [] ? null : new self($freni);
    }

    public function freno(string $nome): ?Freno
    {
        return $this->freni[$nome] ?? null;
    }

    /** @return array<string, Freno> nell'ordine della politica */
    public function tutti(): array
    {
        return $this->freni;
    }

    /**
     * Gli elementi di una lista, per nome, coi soli parametri chiesti. Un nome è una String (`"nome"`) o un Token; un parametro
     * ripetuto vale per l'ultima volta (RFC 8941 §4.2.3.2), un nome ripetuto per la prima.
     *
     * @param  list<string>  $chiesti
     * @return array<string, array<string, int>>
     */
    private static function lista(string $valore, array $chiesti, string $obbligatorio): array
    {
        $elementi = [];

        foreach (self::elementi($valore) as $elemento) {
            $letto = self::elemento($elemento, $chiesti);

            if ($letto === null || ! isset($letto[1][$obbligatorio]) || isset($elementi[$letto[0]])) {
                continue;
            }

            $elementi[$letto[0]] = $letto[1];
        }

        return $elementi;
    }

    /**
     * La lista divisa alle virgole che non stanno in una String.
     *
     * @return list<string>
     */
    private static function elementi(string $valore): array
    {
        preg_match_all('/(?:"(?:[^"\\\\]|\\\\.)*"?|[^,"])+/', $valore, $pezzi);

        return array_values(array_filter(array_map('trim', $pezzi[0]), fn (string $pezzo) => $pezzo !== ''));
    }

    /**
     * Un elemento: il nome e i parametri chiesti, interi; `null` se la sintassi non torna o un parametro chiesto non è un intero.
     *
     * @param  list<string>  $chiesti
     * @return ?array{string, array<string, int>}
     */
    private static function elemento(string $elemento, array $chiesti): ?array
    {
        $valore = '(?:"(?:[^"\\\\]|\\\\[\\\\"])*"|[A-Za-z*][!#$%&\'*+\-.^_`|~:\/0-9A-Za-z]*|-?\d+(?:\.\d+)?|\?[01]|:[A-Za-z0-9+\/=]*:)';

        if (preg_match('/^('.$valore.')((?:\s*;\s*[a-z*][a-z0-9_\-.*]*(?:='.$valore.')?)*)$/', $elemento, $parti) !== 1) {
            return null;
        }

        $nome = $parti[1];

        if (str_starts_with($nome, '"')) {
            $nome = stripcslashes(substr($nome, 1, -1));
        } elseif (preg_match('/^[A-Za-z*]/', $nome) !== 1) {
            return null;
        }

        preg_match_all('/;\s*([a-z*][a-z0-9_\-.*]*)(?:=('.$valore.'))?/', $parti[2], $coppie, PREG_SET_ORDER);
        $parametri = [];

        foreach ($coppie as $coppia) {
            if (in_array($coppia[1], $chiesti, true)) {
                $parametri[$coppia[1]] = $coppia[2] ?? '';
            }
        }

        foreach ($parametri as $chiave => $intero) {
            if (preg_match('/^\d{1,15}$/', $intero) !== 1) {
                return null;
            }

            $parametri[$chiave] = (int) $intero;
        }

        return $nome === '' ? null : [$nome, $parametri];
    }
}
