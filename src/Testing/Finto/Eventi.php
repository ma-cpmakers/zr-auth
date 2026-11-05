<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request;
use InvalidArgumentException;
use LogicException;

/**
 * Gli eventi del workspace nel backoffice finto (#2853): `eventi.elenca` e `eventi.ultimo.mostra`, con la forma dello schema
 * Evento (Forme::evento), l'ordine di `sequence`, i 30 giorni dall'istante dell'evento e il 410 `cursore_scaduto` di EventiController.
 *
 * Il finto non modella gli eventi che le scritture lasciano nel backoffice vero: ogni workspace ha la sua nascita
 * (`com.zeiras.workspace.creato`, `sequence` 1) e gli altri li mette il test con registraEvento(). Il `sequence` è per workspace e
 * cresce di uno, senza buchi.
 */
trait Eventi
{
    /** Per quanti giorni dall'istante dell'evento eventi.elenca lo legge (EventiController::GIORNI). */
    private const EVENTI_GIORNI = 30;

    /** Le cifre del `sequence` (Evento::CIFRE). */
    private const EVENTI_CIFRE = 12;

    /** @var array<string, list<array<string, mixed>>> gli eventi di ogni workspace, in ordine di `sequence` */
    private array $eventiDelWorkspace = [];

    /**
     * Un evento nel log del workspace, come lo scrive il backoffice con una scrittura: il `sequence` è il successivo, l'istante
     * è adesso se il test non lo dice (e si legge per 30 giorni da lì), l'`id` è un ULID nuovo. `$autore` è l'id di una persona
     * del finto o null, `$causa` l'operationId che l'ha prodotto o null.
     *
     * @param  array<string, mixed>  $workspace  un workspace di workspace()
     * @param  array<string, mixed>  $data  il `data` dell'evento: un oggetto anche se vuoto
     * @return array<string, mixed> l'evento, come lo dà eventi.elenca
     */
    public function registraEvento(array $workspace, string $tipo, string $subject, array $data = [], ?CarbonImmutable $istante = null, ?string $autore = null, ?string $causa = null): array
    {
        if ($autore !== null && ! isset($this->persone[$autore])) {
            throw new InvalidArgumentException('La persona non è del finto: nasce con persona().');
        }

        $evento = $this->scriviEvento($this->workspaceDelFinto($workspace), $tipo, $subject, $data, $istante, $autore, $causa);

        // Come lo legge chi chiama la lista: gli oggetti JSON sono array, e un `data` vuoto è [].
        return json_decode(json_encode($evento, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /** La nascita di un workspace (Workspace::booted del backoffice): il primo evento, con l'id e l'istante in `data`. */
    private function nascitaDelWorkspace(string $workspace, string $proprietario): void
    {
        $adesso = now()->toImmutable()->startOfMillisecond();
        $this->workspaceCreatoIl[$workspace] = $adesso;

        $this->scriviEvento($workspace, 'com.zeiras.workspace.creato', '/v1/workspace', ['id' => $workspace, 'aggiornata_il' => self::iso($adesso)], $adesso, $proprietario, 'io.workspace.crea');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function scriviEvento(string $workspace, string $tipo, string $subject, array $data, ?CarbonImmutable $istante, ?string $autore, ?string $causa): array
    {
        // L'ordine delle chiavi è quello di Forme::evento; `data` resta un oggetto anche se è vuoto (in JSON un array vuoto è una lista).
        $evento = [
            'specversion' => '1.0',
            'id' => self::id(),
            'source' => 'https://api.zeiras.com/workspace/'.$workspace,
            'type' => $tipo,
            'subject' => $subject,
            'time' => self::iso($istante ?? now()->toImmutable()->startOfMillisecond()),
            'sequence' => str_pad((string) (count($this->eventiDelWorkspace[$workspace] ?? []) + 1), self::EVENTI_CIFRE, '0', STR_PAD_LEFT),
            'autore' => $autore,
            'causa' => $causa,
            'datacontenttype' => 'application/json',
            'data' => (object) $data,
        ];

        $this->eventiDelWorkspace[$workspace][] = $evento;

        return $evento;
    }

    /**
     * eventi.elenca (EventiController::elenca): gli eventi del workspace del gettone in ordine di `sequence`, per i 30 giorni dall'istante
     * dell'evento. `dopo` (da 1 a 12 cifre, anche senza gli zeri davanti) parte dall'evento dopo; non va col `cursore` (422 `dopo`).
     * Il punto di chi chiama vale se l'evento subito dopo è nei 30 giorni o se è l'ultimo; oltre l'ultimo, o con l'evento dopo più
     * vecchio, è 410 `cursore_scaduto`, dopo i 422 sui parametri.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaEventi(Request $richiesta): array
    {
        $workspace = $this->delWorkspace($richiesta);

        return $this->pagina(
            $richiesta, 'eventi.elenca', 'sequence',
            fn () => $this->eventiDaLeggere($workspace, self::query($richiesta)),
            fn (array $evento) => [$evento['sequence']],
            fn (string $sequence) => [$sequence],
            regole: ['dopo' => ['sometimes', 'string', function (string $attributo, mixed $valore, Closure $fail) {
                // \z e non $: il $ di PCRE accetta anche un a capo in fondo.
                if (! is_string($valore) || preg_match('/^[0-9]{1,'.self::EVENTI_CIFRE.'}\z/', $valore) !== 1) {
                    $this->testi->dichiara($attributo, 'formato');
                    $fail('regole.sequenza')->translate();
                }
            }, 'prohibits:cursore']],
        );
    }

    /**
     * Gli eventi da cui parte la pagina, e il 410 se il punto è scaduto. Con un cursore la pagina taglia da sé; con `dopo` qui.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function eventiDaLeggere(string $workspace, array $query): array
    {
        $eventi = $this->eventiDelWorkspace[$workspace] ?? [];
        $soglia = self::iso(now()->toImmutable()->subDays(self::EVENTI_GIORNI));
        // Un parametro vuoto non arriva qui: per la validazione è null, e `dopo=` è 422 come nel backoffice.
        $dopoDato = isset($query['dopo']) ? (int) $query['dopo'] : null;
        $cursore = isset($query['cursore']) ? (int) $this->leggiCursore('eventi.elenca', 'sequence', $query['cursore']) : null;
        $punto = $dopoDato ?? $cursore;

        if ($punto === null) {
            // Dal più vecchio evento dei 30 giorni in poi, anche se uno dopo di lui avesse un istante più vecchio (il numero comanda).
            foreach ($eventi as $indice => $evento) {
                if ($evento['time'] >= $soglia) {
                    return array_slice($eventi, $indice);
                }
            }

            return [];
        }

        $dopo = array_values(array_filter($eventi, fn (array $evento) => (int) $evento['sequence'] > $punto));

        // I numeri non hanno buchi: il primo evento dopo il punto, se c'è, è quello subito dopo.
        if ($dopo === [] ? $punto > count($eventi) : $dopo[0]['time'] < $soglia) {
            throw new Problema('cursore_scaduto');
        }

        return $dopoDato !== null ? $dopo : $eventi;
    }

    /**
     * eventi.ultimo.mostra (UltimoEventoController::mostra): l'ultimo evento del workspace del gettone, anche oltre i 30 giorni.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraUltimoEvento(Request $richiesta): array
    {
        $eventi = $this->eventiDelWorkspace[$this->delWorkspace($richiesta)] ?? [];

        return [200, ['data' => $eventi[array_key_last($eventi)] ?? throw new LogicException('Un workspace senza eventi: ogni workspace ha almeno la sua nascita.')]];
    }
}
