<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;

/**
 * Il To-do della persona nel backoffice finto (PM-11, #2464 #2465 #2466 #2467 #2550-#2553): le liste (`board.todo.liste.elenca`, `crea`,
 * `modifica`), i to-do (`board.todo.elementi.elenca`, `crea`, `mostra`, `modifica`, `elimina`, `completamento.crea` e `.elimina`), il
 * riepilogo (`board.todo.riepilogo.mostra`) e i tag (`board.todo.tag.elenca`), con le regole, i pointer e l'ordine di `TodoListeController`,
 * `TodoElementiController`, `TodoRiepilogoController`, `TodoTagController` e `App\Board\Todo` del backoffice. Sono metodi dell'app `pm`,
 * dei tre ruoli, ma una lista e un to-do li legge e li scrive solo chi li ha creati: per ogni altro il loro id è 404 uguale a uno che non c'è,
 * e una `lista_id` o un `dopo_id` che non sono suoi sono 422 sul campo. «Personale» nasce alla prima lettura della persona. Eliminare un to-do
 * lo toglie dall'API. Il finto non emette gli eventi. L'`ETag` non è lo stesso valore del backoffice, ma come lui dipende dall'id e da
 * `aggiornato_il` (le rinumerazioni dei vicini non lo toccano).
 */
trait Todo
{
    private const TODO_VISTE = ['oggi', 'settimana', 'tutti', 'fatti'];

    private const TODO_COLORI = ['pine', 'citrus', 'coral', 'sky', 'plum', 'neutral'];

    private const TODO_ORDINI = ['posizione', 'giorno', 'colore'];

    /** L'ordine dei colori: i sei come li elenca il contratto, «nessuno» (`neutral`) per ultimo. */
    private const TODO_ORDINE_COLORI = ['pine' => 0, 'sky' => 1, 'citrus' => 2, 'coral' => 3, 'plum' => 4, 'neutral' => 5];

    private const TODO_NOMI_PERSONALE = ['it' => 'Personale', 'en' => 'Personal', 'es' => 'Personal'];

    private const TODO_LISTE_TETTO = 100;

    private const TODO_APERTI_TETTO = 1000;

    private const TODO_TAG_TETTO = 200;

    private const TODO_NOME = 100;

    private const TODO_TITOLO = 500;

    private const TODO_NOTE = 10000;

    private const TODO_TAG = 20;

    private const TODO_TAG_LUNGHEZZA = 50;

    private const TODO_GIORNO_MINIMO = '2000-01-01';

    private const TODO_GIORNO_MASSIMO = '2100-12-31';

    /** Il passo fra le posizioni dei vicini, e la soglia oltre cui la coda non cresce più (Posizioni::PASSO e SOGLIA). */
    private const TODO_PASSO = 1024;

    private const TODO_SOGLIA = 1_000_000_000;

    /** @var array<string, array<string, mixed>> le liste per id */
    private array $todoListe = [];

    /** @var array<string, array<string, mixed>> i to-do per id; quelli eliminati restano con `archiviato` */
    private array $todoElementi = [];

    // ———————————————————————————————————————— Liste

    /**
     * board.todo.liste.elenca (TodoListeController::elenca): le liste non archiviate della persona, «Personale» per prima, poi per
     * posizione. Senza pagine.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaListeTodo(Request $richiesta): array
    {
        $chi = $this->conPm($richiesta);
        $grezza = self::query($richiesta);
        $estranei = array_diff(array_map('strval', array_keys($grezza)), ['scheda_id']);
        $this->testi->validaQuery($grezza, array_fill_keys($estranei, [function (string $attributo, mixed $valore, Closure $rifiuta) {
            $this->testi->dichiara($attributo, Regola::CAMPO_ESTRANEO);
            $rifiuta('validation.prohibited')->translate(['attribute' => $this->testi->nome($attributo)]);
        }]) + ['scheda_id' => ['sometimes', 'string', 'max:64', $this->schedaDelWorkspaceTodo($chi)]]);
        $this->personaleTodo($chi);

        if (isset($grezza['scheda_id'])) {
            $liste = array_values(array_filter($this->todoListe, fn (array $l) => $l['workspace'] === $chi['workspace'] && $l['tipo'] === 'progetto' && $l['scheda'] === $grezza['scheda_id'] && ! $l['archiviata']));
        } else {
            $liste = $this->listeCoinvolteTodo($chi);
        }

        return [200, ['data' => array_map($this->voceListaTodo(...), $liste)]];
    }

    /**
     * board.todo.liste.crea (TodoListeController::crea): 201 con la lista, la `Location` e l'`ETag`. Il freno prima del corpo, il corpo
     * (422) prima del tetto di cento liste non archiviate (409 `limite_raggiunto`). La lista nasce in coda.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaListaTodo(Request $richiesta, array $corpo): array
    {
        $chi = $this->conPm($richiesta);

        return $this->conIdempotenza($richiesta, 'board.todo.liste.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $this->frena('board.todo.liste.crea:persona:'.$chi['persona'].':'.$chi['workspace'], self::FRENI['board_todo_liste_persona'], self::ORA);
            $campi = $this->testi->validaCorpo($corpo, $this->regoleListaTodo(true));
            $progetto = ($campi['tipo'] ?? 'personale') === 'progetto';
            $scheda = $campi['scheda_id'] ?? null;

            if ($progetto && $scheda === null) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.todo_lista_progetto_vuole_scheda'), 'regola' => Regola::OBBLIGATORIO, 'pointer' => '#/scheda_id']]);
            }

            if (! $progetto && $scheda !== null) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.todo_lista_personale_senza_scheda', ['attribute' => 'scheda_id']), 'regola' => Regola::CAMPO_VIETATO, 'pointer' => '#/scheda_id']]);
            }

            $this->personaleTodo($chi);

            if ($progetto) {
                $laScheda = $this->schedaDelCampoTodo($chi, (string) $scheda);

                if ($laScheda['archiviata_il'] !== null) {
                    throw new Problema('scheda_archiviata');
                }

                $esistente = array_values(array_filter($this->todoListe, fn (array $l) => $l['workspace'] === $chi['workspace'] && $l['tipo'] === 'progetto' && $l['scheda'] === $laScheda['id']))[0] ?? null;

                if ($esistente !== null) {
                    throw new Problema('lista_gia_esistente', estensioni: ['lista_id' => $esistente['id']]);
                }
            } elseif (count($this->listeDellaPersonaTodo($chi)) >= self::TODO_LISTE_TETTO) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::TODO_LISTE_TETTO]);
            }

            $id = self::id();
            $ora = now()->toImmutable()->startOfMillisecond();
            $this->todoListe[$id] = [
                'id' => $id, 'workspace' => $chi['workspace'], 'persona' => $chi['persona'], 'nome' => trim((string) $campi['nome']),
                'colore' => $campi['colore'] ?? 'neutral', 'tipo' => $progetto ? 'progetto' : 'personale', 'predefinita' => false, 'ordine' => 'posizione',
                'scheda' => $progetto ? $laScheda['id'] : null, 'board' => $progetto ? $laScheda['board'] : null,
                'posizione' => $progetto ? 0 : $this->inCodaTodo(array_column($this->listeDellaPersonaTodo($chi), 'posizione')),
                'archiviata' => false, 'creata_il' => $ora, 'aggiornata_il' => $ora,
            ];
            $voce = $this->voceListaTodo($this->todoListe[$id]);

            return [201, ['data' => $voce], ['Location' => "/v1/board/todo/liste/{$id}", 'ETag' => $voce['etag']]];
        });
    }

    /**
     * board.todo.liste.modifica (TodoListeController::modifica): JSON Merge Patch di nome, colore e ordine ricordato. La lista si cerca
     * prima del corpo (404), poi l'`If-Match` (412), poi il corpo (422). Un corpo che non cambia niente non scrive. «Personale» si
     * rinomina e si colora.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function modificaListaTodo(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conPm($richiesta);
        $lista = $this->listaDelPercorsoTodo($chi, $id);
        $this->scrivibileTodo($lista);
        $this->pretendiLaVersioneCrm($richiesta, $this->versioneTodo($lista, 'aggiornata_il'));
        $campi = $this->modificaTodo($corpo, $this->regoleListaTodo(false), ['nome', 'colore', 'ordine']);
        $cambi = [];

        if (array_key_exists('nome', $campi) && trim((string) $campi['nome']) !== $lista['nome']) {
            $cambi['nome'] = trim((string) $campi['nome']);
        }

        foreach (['colore', 'ordine'] as $campo) {
            if (array_key_exists($campo, $campi) && $campi[$campo] !== $lista[$campo]) {
                $cambi[$campo] = $campi[$campo];
            }
        }

        if ($cambi !== []) {
            $this->todoListe[$id] = [...$lista, ...$cambi, 'aggiornata_il' => $this->dopoTodo($lista['aggiornata_il'])];
        }

        $voce = $this->voceListaTodo($this->todoListe[$id]);

        return [200, ['data' => $voce], ['ETag' => $voce['etag']]];
    }

    /**
     * Le regole dei campi di una lista (Todo::regoleDellaLista): `tipo`, `predefinita` e `posizione` non si scrivono.
     *
     * @return array<string, mixed>
     */
    private function regoleListaTodo(bool $creazione): array
    {
        return [
            'nome' => ['bail', $creazione ? 'required' : 'filled', 'string', 'max:'.self::TODO_NOME, new NomeScritto],
            'colore' => ['bail', 'sometimes', Rule::in(self::TODO_COLORI)],
            ...($creazione
                ? ['tipo' => ['bail', 'sometimes', 'string', Rule::in(['personale', 'progetto'])], 'scheda_id' => ['bail', 'sometimes', 'string', 'max:64']]
                : ['ordine' => ['bail', 'sometimes', Rule::in(self::TODO_ORDINI)], 'tipo' => [$this->rifiutaIlCampoTodo('regole.campo_di_nessun_metodo')], 'scheda_id' => [$this->rifiutaIlCampoTodo('regole.campo_di_nessun_metodo')]]),
            'predefinita' => [$this->rifiutaIlCampoTodo('regole.campo_di_nessun_metodo')],
            'posizione' => [$this->rifiutaIlCampoTodo('regole.campo_di_nessun_metodo')],
        ];
    }

    /**
     * Una lista com'è nella risposta (Forme::todoLista).
     *
     * @param  array<string, mixed>  $lista
     * @return array<string, mixed>
     */
    private function voceListaTodo(array $lista): array
    {
        return [
            'id' => $lista['id'], 'nome' => $lista['nome'], 'colore' => $lista['colore'], 'tipo' => $lista['tipo'],
            'scheda_id' => $lista['scheda'] ?? null, 'board_id' => $lista['board'] ?? null, 'predefinita' => $lista['predefinita'], 'ordine' => $lista['ordine'], 'posizione' => $lista['posizione'],
            'creata_il' => self::iso($lista['creata_il']), 'aggiornata_il' => self::iso($lista['aggiornata_il']),
            'etag' => self::etagCrm($this->versioneTodo($lista, 'aggiornata_il')),
        ];
    }

    // ———————————————————————————————————————— To-do

    /**
     * board.todo.elementi.elenca (TodoElementiController::elenca): una vista (`oggi`, `settimana`, `tutti`, `fatti`), una lista
     * (aperti, o fatti con `fatti=true`), una scheda oppure `assegnato_a` (`me` o l'id di chi chiama: i to-do delle liste di progetto assegnati a
     * lui, aperti o fatti con `fatti=true`), nell'ordine chiesto, in forma breve e a cursore; con `totale=true` anche quanti sono, fino a
     * diecimila. Il cursore vale per un solo filtro e un solo ordine.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaElementiTodo(Request $richiesta): array
    {
        $chi = $this->conPm($richiesta);
        $grezza = self::query($richiesta);
        $ha = fn (string $campo): bool => array_key_exists($campo, $grezza);
        $regole = [
            'vista' => ['bail', 'required_without_all:lista_id,scheda_id,assegnato_a', 'string', Rule::in(self::TODO_VISTE), 'prohibits:lista_id,scheda_id,assegnato_a'],
            'scheda_id' => ['bail', 'required_without_all:vista,lista_id,assegnato_a', 'string', 'max:64', 'prohibits:assegnato_a', $this->schedaDelWorkspaceTodo($chi)],
            'lista_id' => ['bail', 'required_without_all:vista,scheda_id,assegnato_a', 'string', 'max:64', 'prohibits:scheda_id,assegnato_a', function (string $attributo, mixed $valore, Closure $rifiuta) use ($chi) {
                if (! $this->haListaTodo($chi, (string) $valore)) {
                    $this->testi->dichiara($attributo, Regola::NON_TROVATO);
                    $rifiuta('regole.lista_del_todo')->translate(['attribute' => $this->testi->nome($attributo)]);
                }
            }],
            'assegnato_a' => ['bail', 'required_without_all:vista,lista_id,scheda_id', 'string', 'max:64', function (string $attributo, mixed $valore, Closure $rifiuta) use ($chi) {
                if ($valore !== 'me' && $valore !== $chi['persona']) {
                    $this->testi->dichiara($attributo, Regola::VALORE_NON_AMMESSO);
                    $rifiuta('regole.todo_assegnato_a_chi_chiama')->translate(['attribute' => $this->testi->nome($attributo)]);
                }
            }],
            'totale' => ['sometimes', 'string', Rule::in(['true', 'false'])],
            'fatti' => ['bail', 'sometimes', 'string', Rule::in(['true', 'false']), function (string $attributo, mixed $valore, Closure $rifiuta) use ($ha) {
                if ($valore === 'true' && ! $ha('lista_id') && ! $ha('assegnato_a')) {
                    $this->testi->dichiara($attributo, Regola::CAMPO_VIETATO);
                    $rifiuta('regole.todo_fatti_con_lista')->translate(['attribute' => $this->testi->nome($attributo)]);
                }
            }],
            'ordine' => ['bail', 'sometimes', 'string', Rule::in(self::TODO_ORDINI), function (string $attributo, mixed $valore, Closure $rifiuta) use ($ha) {
                if (! $ha('lista_id')) {
                    $this->testi->dichiara($attributo, Regola::CAMPO_VIETATO);
                    $rifiuta('regole.todo_ordine_con_lista')->translate(['attribute' => $this->testi->nome($attributo)]);
                }
            }],
        ];
        $vista = $grezza['vista'] ?? null;
        $listaId = $grezza['lista_id'] ?? null;
        $schedaId = $grezza['scheda_id'] ?? null;
        $assegnati = isset($grezza['assegnato_a']);
        $fatti = ($grezza['fatti'] ?? null) === 'true';
        $modo = is_string($vista) ? $vista : (is_string($schedaId) ? 'scheda' : ($assegnati ? ($fatti ? 'fatti' : 'giorno') : ($fatti ? 'fatti' : (string) ($grezza['ordine'] ?? 'posizione'))));
        $lista = 'board.todo.elementi.elenca|'.json_encode(array_map(fn (string $campo) => $grezza[$campo] ?? null, ['vista', 'lista_id', 'scheda_id', 'assegnato_a', 'fatti', 'ordine']), JSON_THROW_ON_ERROR);
        $totale = 0;
        $voci = function () use ($chi, $vista, $listaId, $schedaId, $fatti, $assegnati, &$totale) {
            $oggi = $this->oggiTodo($chi['persona']);
            $righe = array_filter(
                $this->elementiVisibiliTodo($chi),
                fn (array $e) => $this->inVistaTodo($e, is_string($vista) ? $vista : null, is_string($listaId) ? strtolower($listaId) : null, is_string($schedaId) ? $schedaId : null, $fatti, $oggi, $chi['persona'], $assegnati),
            );
            $totale = count($righe);

            return array_map(fn (array $e) => $this->voceElementoTodo($e, false), array_values($righe));
        };

        [$stato, $corpo] = $this->pagina(
            $richiesta,
            $lista,
            'id',
            $voci,
            fn (array $voce) => $this->chiaveTodo($this->todoElementi[$voce['id']], $modo),
            fn (string $id) => isset($this->todoElementi[$id]) && $this->todoElementi[$id]['workspace'] === $chi['workspace'] && $this->haListaTodo($chi, $this->todoElementi[$id]['lista'])
                ? $this->chiaveTodo($this->todoElementi[$id], $modo)
                : null,
            regole: $regole,
        );

        // Con `totale=true` anche quanti sono, qualunque siano `limite` e cursore: fino a 10.000, oltre `totale_almeno`.
        if (($grezza['totale'] ?? null) === 'true') {
            $corpo['totale'] = min($totale, 10000);

            if ($totale > 10000) {
                $corpo['totale_almeno'] = true;
            }
        }

        return [$stato, $corpo];
    }

    /**
     * board.todo.elementi.crea (TodoElementiController::crea): 201 con il to-do intero, la `Location` e l'`ETag`. Il freno prima del corpo,
     * il corpo (422) prima del tetto di mille aperti per lista (409 `limite_raggiunto`). Senza `lista_id` va in «Personale»; nasce in coda.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaElementoTodo(Request $richiesta, array $corpo): array
    {
        $chi = $this->conPm($richiesta);

        return $this->conIdempotenza($richiesta, 'board.todo.elementi.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $this->frena('board.todo.elementi.crea:persona:'.$chi['persona'].':'.$chi['workspace'], self::FRENI['board_todo_elementi_persona'], self::ORA);
            $campi = $this->testi->validaCorpo($corpo, $this->regoleElementoTodo(true));
            $this->oraSenzaGiornoTodo($campi['giorno'] ?? null, $campi['ora'] ?? null);
            $personale = $this->personaleTodo($chi);
            $lista = match (true) {
                isset($campi['lista_id']) => $this->listaDelCampoTodo($chi, (string) $campi['lista_id']),
                isset($campi['scheda_id']) => $this->listaDellaSchedaTodo($chi, (string) $campi['scheda_id']),
                default => $personale,
            };
            $this->scrivibileTodo($lista);
            $legami = $this->legamiTodo($chi, $lista, $campi);
            $this->tettoDegliApertiTodo($lista['id']);

            $id = self::id();
            $ora = now()->toImmutable()->startOfMillisecond();
            $this->todoElementi[$id] = [
                'id' => $id, 'workspace' => $chi['workspace'], 'persona' => $chi['persona'], 'lista' => $lista['id'], 'scheda' => $legami['scheda'], 'assegnato' => $legami['assegnato'],
                'titolo' => trim((string) $campi['titolo']), 'note' => $campi['note'] ?? null, 'giorno' => $campi['giorno'] ?? null, 'ora' => $campi['ora'] ?? null,
                'tag' => $this->tagSenzaDoppioniTodo($campi['tag'] ?? []), 'colore' => $campi['colore'] ?? 'neutral',
                'posizione' => $this->posizioneTodo($lista['id'], null, null, false), 'fatto_il' => null, 'archiviato' => false,
                'creato_il' => $ora, 'aggiornato_il' => $ora,
            ];
            $voce = $this->voceElementoTodo($this->todoElementi[$id]);

            return [201, ['data' => $voce], ['Location' => "/v1/board/todo/elementi/{$id}", 'ETag' => $voce['etag']]];
        });
    }

    /**
     * board.todo.elementi.mostra (TodoElementiController::mostra): il to-do intero, con le `note` e l'`ETag`; con un `If-None-Match` che lo
     * contiene, 304 senza corpo.
     *
     * @return array{int, array<string, mixed>|null, array<string, string>}
     */
    private function mostraElementoTodo(Request $richiesta, string $id): array
    {
        $chi = $this->conPm($richiesta);
        $voce = $this->voceElementoTodo($this->elementoDelPercorsoTodo($chi, $id));

        if (self::trovaEtagCrm($richiesta->toPsrRequest()->getHeaderLine('If-None-Match'), $voce['etag'], true)) {
            return [304, null, ['ETag' => $voce['etag']]];
        }

        return [200, ['data' => $voce], ['ETag' => $voce['etag']]];
    }

    /**
     * board.todo.elementi.modifica (TodoElementiController::modifica): JSON Merge Patch di titolo, giorno, ora, tag, note, colore, lista e
     * posto. Il to-do si cerca prima del corpo (404), poi l'`If-Match` (412), poi il corpo (422); `lista_id` lo mette in coda all'altra
     * lista, `dopo_id` subito dopo un altro to-do della lista di arrivo (`null`: in testa). I vicini si rinumerano senza toccare il loro
     * `aggiornato_il`. Un campo mandato uguale a ciò che c'è non cambia niente.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function modificaElementoTodo(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conPm($richiesta);
        $corrente = $this->elementoDelPercorsoTodo($chi, $id);
        $arrivo = isset($corpo['lista_id']) && is_string($corpo['lista_id']) && strtolower($corpo['lista_id']) !== $corrente['lista'] ? $this->listaDelCampoTodo($chi, $corpo['lista_id']) : null;
        $this->scrivibileTodo($this->todoListe[$corrente['lista']]);

        if ($arrivo !== null) {
            $this->scrivibileTodo($arrivo);
        }

        $this->pretendiLaVersioneCrm($richiesta, $this->versioneTodo($corrente, 'aggiornato_il'));
        $campi = $this->modificaTodo($corpo, $this->regoleElementoTodo(false), ['titolo', 'lista_id', 'giorno', 'ora', 'tag', 'note', 'colore', 'assegnato_a', 'scheda_id', 'dopo_id']);
        $cambi = [];

        if (array_key_exists('titolo', $campi)) {
            $cambi['titolo'] = trim((string) $campi['titolo']);
        }

        if (array_key_exists('note', $campi)) {
            $cambi['note'] = $campi['note'];
        }

        if (array_key_exists('colore', $campi)) {
            $cambi['colore'] = $campi['colore'];
        }

        if (array_key_exists('tag', $campi)) {
            $cambi['tag'] = $this->tagSenzaDoppioniTodo($campi['tag']);
        }

        // Togliere il giorno toglie anche l'ora; un'ora senza giorno non c'è.
        $giorno = array_key_exists('giorno', $campi) ? $campi['giorno'] : $corrente['giorno'];
        $ora = array_key_exists('ora', $campi) ? $campi['ora'] : ($giorno === null ? null : $corrente['ora']);
        $this->oraSenzaGiornoTodo($giorno, $ora);
        $cambi['giorno'] = $giorno;
        $cambi['ora'] = $ora;
        $cambi += $this->collocaTodo($chi, $corrente, $campi);
        $cambi += $this->legamiCambiatiTodo($chi, $corrente, $campi, $cambi['lista'] ?? null);
        $assegnatarioTolto = $this->togliAssegnatarioDiUnaltraBoardTodo($chi, $corrente, $campi, $cambi);

        $cambiati = array_filter($cambi, fn (mixed $valore, string $campo) => $valore !== $corrente[$campo], ARRAY_FILTER_USE_BOTH);

        if ($cambiati !== []) {
            $this->todoElementi[$id] = [...$corrente, ...$cambiati, 'aggiornato_il' => $this->dopoTodo($corrente['aggiornato_il'])];
        }

        $voce = $this->voceElementoTodo($this->todoElementi[$id]);

        return [200, ['data' => $voce, 'assegnatario_tolto' => $assegnatarioTolto], ['ETag' => $voce['etag']]];
    }

    /**
     * Il to-do passa a una lista `progetto` di un'altra board (Todo::togliAssegnatarioDiUnaltraBoard, #2812): l'assegnatario che non è più
     * membro del workspace si toglie, e si dice se è successo. Un assegnatario scritto dalla richiesta, nessuna `lista_id` nuova o la stessa
     * board non toccano niente.
     *
     * @param  array<string, mixed>  $chi
     * @param  array<string, mixed>  $corrente
     * @param  array<string, mixed>  $campi
     * @param  array<string, mixed>  $cambi
     */
    private function togliAssegnatarioDiUnaltraBoardTodo(array $chi, array $corrente, array $campi, array &$cambi): bool
    {
        if (! isset($cambi['lista']) || array_key_exists('assegnato_a', $campi) || ($corrente['assegnato'] ?? null) === null) {
            return false;
        }

        $arrivo = $this->todoListe[$cambi['lista']];

        if ($arrivo['tipo'] !== 'progetto' || $arrivo['board'] === $this->todoListe[$corrente['lista']]['board'] || isset($this->membri[$chi['workspace']][$corrente['assegnato']])) {
            return false;
        }

        $cambi['assegnato'] = null;

        return true;
    }

    /**
     * board.todo.elementi.elimina (TodoElementiController::elimina): toglie il to-do dall'API, 204; la seconda volta è 404.
     *
     * @return array{int, null}
     */
    private function eliminaElementoTodo(Request $richiesta, string $id): array
    {
        $chi = $this->conPm($richiesta);
        $corrente = $this->elementoDelPercorsoTodo($chi, $id);
        $this->scrivibileTodo($this->todoListe[$corrente['lista']]);
        $this->todoElementi[$id]['archiviato'] = true;

        return [204, null];
    }

    /**
     * board.todo.elementi.completamento.crea (TodoElementiController::completamentoCrea): `fatto_il` all'istante della prima chiamata, 200
     * con il to-do. Imposta uno stato e non lo inverte: su un to-do già fatto non cambia niente, `aggiornato_il` compreso.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function completamentoCreaTodo(Request $richiesta, string $id): array
    {
        $chi = $this->conPm($richiesta);
        $corrente = $this->elementoDelPercorsoTodo($chi, $id);
        $this->scrivibileTodo($this->todoListe[$corrente['lista']]);

        if ($corrente['fatto_il'] === null) {
            $ora = $this->dopoTodo($corrente['aggiornato_il']);
            $this->todoElementi[$id] = [...$corrente, 'fatto_il' => $ora, 'aggiornato_il' => $ora];
        }

        $voce = $this->voceElementoTodo($this->todoElementi[$id]);

        return [200, ['data' => $voce], ['ETag' => $voce['etag']]];
    }

    /**
     * board.todo.elementi.completamento.elimina (TodoElementiController::completamentoElimina): riapre il to-do, che riprende la sua
     * posizione fra gli aperti; su uno aperto non cambia niente. Se la lista ha già mille aperti, 409 `limite_raggiunto`.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function completamentoEliminaTodo(Request $richiesta, string $id): array
    {
        $chi = $this->conPm($richiesta);
        $corrente = $this->elementoDelPercorsoTodo($chi, $id);
        $this->scrivibileTodo($this->todoListe[$corrente['lista']]);

        if ($corrente['fatto_il'] !== null) {
            $this->tettoDegliApertiTodo($corrente['lista']);
            $this->todoElementi[$id] = [...$corrente, 'fatto_il' => null, 'aggiornato_il' => $this->dopoTodo($corrente['aggiornato_il'])];
        }

        $voce = $this->voceElementoTodo($this->todoElementi[$id]);

        return [200, ['data' => $voce], ['ETag' => $voce['etag']]];
    }

    /**
     * board.todo.riepilogo.mostra (TodoRiepilogoController::mostra): i numeri del To-do della persona: oggi nel suo fuso, in ritardo, di
     * oggi, settimana (da domani a fra sette giorni), aperti, fatti, assegnati (i to-do aperti di progetto assegnati a chi chiama) e per ogni
     * lista non archiviata, nell'ordine delle liste.
     *
     * @return array{int, array<string, mixed>}
     */
    private function riepilogoTodo(Request $richiesta): array
    {
        $chi = $this->conPm($richiesta);
        $this->personaleTodo($chi);
        $oggi = $this->oggiTodo($chi['persona']);
        $giorno = $oggi->format('Y-m-d');
        $fra7 = $oggi->addDays(7)->format('Y-m-d');
        $totali = ['in_ritardo' => 0, 'di_oggi' => 0, 'settimana' => 0, 'aperti' => 0, 'fatti' => 0, 'assegnati' => 0];
        $liste = [];

        foreach ($this->listeCoinvolteTodo($chi) as $lista) {
            $dellaLista = array_filter($this->elementiVisibiliTodo($chi), fn (array $e) => $e['lista'] === $lista['id']);
            $aperti = array_filter($dellaLista, fn (array $e) => $e['fatto_il'] === null);
            // Le finestre di giorni sono quelle delle viste: in una lista di progetto contano i soli to-do assegnati a chi chiama.
            $miei = array_filter($aperti, fn (array $e) => $lista['tipo'] === 'personale' || $e['assegnato'] === $chi['persona']);
            $totali['in_ritardo'] += count(array_filter($miei, fn (array $e) => $e['giorno'] !== null && $e['giorno'] < $giorno));
            $totali['di_oggi'] += count(array_filter($miei, fn (array $e) => $e['giorno'] === $giorno));
            $totali['settimana'] += count(array_filter($miei, fn (array $e) => $e['giorno'] !== null && $e['giorno'] > $giorno && $e['giorno'] <= $fra7));
            $totali['aperti'] += count($aperti);
            $totali['fatti'] += count($dellaLista) - count($aperti);
            // I to-do aperti di una lista di progetto assegnati a chi chiama: il numero di `board.todo.elementi.elenca?assegnato_a=me`.
            $totali['assegnati'] += $lista['tipo'] === 'progetto' ? count(array_filter($aperti, fn (array $e) => $e['assegnato'] === $chi['persona'])) : 0;
            $liste[] = ['lista_id' => $lista['id'], 'aperti' => count($aperti), 'fatti' => count($dellaLista) - count($aperti)];
        }

        return [200, ['data' => ['oggi' => $giorno, ...$totali, 'liste' => $liste]]];
    }

    /**
     * board.todo.tag.elenca (TodoTagController::elenca): i tag dei to-do della persona, aperti e fatti, per `usi` decrescenti e poi per nome;
     * senza pagine e al più duecento. Maiuscole e accenti non distinguono i tag, e il `nome` è la grafia dell'uso più recente. Conta i to-do,
     * non le ripetizioni nello stesso to-do. Nessun parametro: uno qualunque è 422 `campo_estraneo` sul suo nome.
     *
     * @return array{int, array<string, mixed>}
     */
    private function tagTodo(Request $richiesta): array
    {
        $chi = $this->conPm($richiesta);
        $estranei = array_map('strval', array_keys(self::query($richiesta)));
        $this->testi->validaQuery(self::query($richiesta), array_fill_keys($estranei, [function (string $attributo, mixed $valore, Closure $rifiuta) {
            $this->testi->dichiara($attributo, Regola::CAMPO_ESTRANEO);
            $rifiuta('validation.prohibited')->translate(['attribute' => $this->testi->nome($attributo)]);
        }]));
        $dal = $this->elementiDellaPersonaTodo($chi);
        usort($dal, fn (array $una, array $altra) => [self::iso($una['aggiornato_il']), $una['id']] <=> [self::iso($altra['aggiornato_il']), $altra['id']]);
        $conti = [];
        $nomi = [];

        foreach ($dal as $elemento) {
            $visti = [];

            foreach ($elemento['tag'] as $tag) {
                $chiave = self::tagPiegatoTodo($tag);

                if (isset($visti[$chiave])) {
                    continue;
                }

                $visti[$chiave] = true;
                $conti[$chiave] = ($conti[$chiave] ?? 0) + 1;
                $nomi[$chiave] = $tag;
            }
        }

        $elenco = [];

        foreach ($conti as $chiave => $usi) {
            $elenco[] = ['chiave' => (string) $chiave, 'nome' => $nomi[$chiave], 'usi' => $usi];
        }

        usort($elenco, fn (array $a, array $b) => [$b['usi'], $a['chiave'], $a['nome']] <=> [$a['usi'], $b['chiave'], $b['nome']]);

        return [200, ['data' => array_map(fn (array $uno) => ['nome' => $uno['nome'], 'usi' => $uno['usi']], array_slice($elenco, 0, self::TODO_TAG_TETTO))]];
    }

    /**
     * Il posto del to-do in una modifica: `lista_id` lo manda in coda all'altra lista (e conta nel suo tetto di aperti), `dopo_id` subito
     * dopo un altro to-do della lista di arrivo o in testa (`null`). Un `dopo_id` che è il to-do stesso, che non sta nella lista di arrivo
     * o che non è della persona è 422 `valore_non_ammesso` su `#/dopo_id`, mai 404.
     *
     * @param  array{persona: string, workspace: string, accesso: string}  $chi
     * @param  array<string, mixed>  $corrente
     * @param  array<string, mixed>  $campi
     * @return array<string, mixed> i campi `lista` e `posizione` che cambiano
     */
    private function collocaTodo(array $chi, array $corrente, array $campi): array
    {
        $destinazione = $corrente['lista'];
        $cambiaLista = false;
        $cambi = [];

        if (isset($campi['lista_id']) && strtolower((string) $campi['lista_id']) !== $corrente['lista']) {
            $arrivo = $this->listaDelCampoTodo($chi, (string) $campi['lista_id']);

            if ($arrivo['tipo'] !== $this->todoListe[$corrente['lista']]['tipo']) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.todo_lista_stesso_tipo'), 'regola' => Regola::VALORE_NON_AMMESSO, 'pointer' => '#/lista_id']]);
            }

            $destinazione = $arrivo['id'];
            $cambiaLista = true;

            if ($corrente['fatto_il'] === null) {
                $this->tettoDegliApertiTodo($destinazione);
            }
        }

        if (array_key_exists('dopo_id', $campi)) {
            $ancora = null;

            if ($campi['dopo_id'] !== null) {
                $trovato = $this->todoElementi[strtolower((string) $campi['dopo_id'])] ?? null;
                $ancora = $trovato !== null && $trovato['workspace'] === $chi['workspace'] && ! $trovato['archiviato']
                    && $trovato['lista'] === $destinazione && $trovato['id'] !== $corrente['id']
                    ? $trovato
                    : throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.todo_dopo_id', ['attribute' => 'dopo_id']), 'regola' => Regola::VALORE_NON_AMMESSO, 'pointer' => '#/dopo_id']]);
            }

            // Già dopo di lui, nella sua lista: niente da spostare.
            if ($cambiaLista || ($this->precedenteTodo($corrente)['id'] ?? null) !== ($ancora['id'] ?? null)) {
                $cambi['posizione'] = $this->posizioneTodo($destinazione, $corrente['id'], $ancora, $ancora === null);
            }
        } elseif ($cambiaLista) {
            $cambi['posizione'] = $this->posizioneTodo($destinazione, null, null, false);
        }

        if ($cambiaLista) {
            $cambi['lista'] = $destinazione;
        }

        return $cambi;
    }

    /**
     * Le regole dei campi di un to-do (Todo::regoleDelElemento): `fatto_il` e `posizione` non si scrivono.
     *
     * @return array<string, mixed>
     */
    private function regoleElementoTodo(bool $creazione): array
    {
        return [
            'titolo' => ['bail', $creazione ? 'required' : 'filled', 'string', 'max:'.self::TODO_TITOLO, new NomeScritto],
            'lista_id' => ['bail', 'filled', 'string', 'max:64'],
            'scheda_id' => ['bail', 'sometimes', 'nullable', 'string', 'max:64'],
            'assegnato_a' => ['bail', 'sometimes', 'nullable', 'string', 'max:64'],
            'giorno' => ['bail', 'sometimes', ...($creazione ? [] : ['nullable']), 'string', 'date_format:Y-m-d', function (string $attributo, mixed $valore, Closure $rifiuta) {
                if (is_string($valore) && ($valore < self::TODO_GIORNO_MINIMO || $valore > self::TODO_GIORNO_MASSIMO)) {
                    $this->testi->dichiara($attributo, Regola::FUORI_INTERVALLO);
                    $rifiuta('regole.todo_giorno_fuori_intervallo')->translate(['attribute' => $this->testi->nome($attributo)]);
                }
            }],
            'ora' => ['bail', 'sometimes', ...($creazione ? [] : ['nullable']), 'string', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'],
            'tag' => ['bail', 'sometimes', 'array', 'list', 'max:'.self::TODO_TAG],
            'tag.*' => ['bail', 'string', 'min:1', 'max:'.self::TODO_TAG_LUNGHEZZA, 'not_regex:/#/', new NomeScritto],
            'note' => ['bail', 'sometimes', 'nullable', 'string', 'max:'.self::TODO_NOTE, new SenzaCarattereNullo],
            'colore' => ['bail', 'sometimes', Rule::in(self::TODO_COLORI)],
            'fatto_il' => [$this->rifiutaIlCampoTodo('regole.campo_di_un_altro_metodo', ['metodo' => 'board.todo.elementi.completamento.crea'])],
            'posizione' => [$this->rifiutaIlCampoTodo('regole.todo_posizione')],
            ...($creazione ? [] : ['dopo_id' => ['bail', 'sometimes', 'nullable', 'string', 'max:64']]),
        ];
    }

    /**
     * Un to-do com'è nella risposta (Forme::todoElemento): la forma breve, senza `note`, è quella dell'elenco.
     *
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    private function voceElementoTodo(array $e, bool $intero = true): array
    {
        return [
            'id' => $e['id'], 'lista_id' => $e['lista'], 'scheda_id' => $e['scheda'] ?? null, 'assegnato_a' => $e['assegnato'] ?? null, 'titolo' => $e['titolo'],
            ...($intero ? ['note' => $e['note'] ?? ''] : []),
            'fatto_il' => $e['fatto_il'] === null ? null : self::iso($e['fatto_il']), 'giorno' => $e['giorno'], 'ora' => $e['ora'],
            'tag' => $e['tag'], 'colore' => $e['colore'], 'posizione' => $e['posizione'],
            'creato_il' => self::iso($e['creato_il']), 'aggiornato_il' => self::iso($e['aggiornato_il']),
            'etag' => self::etagCrm($this->versioneTodo($e, 'aggiornato_il')),
        ];
    }

    // ———————————————————————————————————————— Letture e supporti

    /**
     * Ciò che l'`ETag` di una lista o di un to-do confronta: l'id e l'istante di modifica, non la posizione (i vicini si rinumerano).
     *
     * @param  array<string, mixed>  $riga
     * @return array<string, string>
     */
    private function versioneTodo(array $riga, string $campo): array
    {
        return ['id' => $riga['id'], $campo => self::iso($riga[$campo])];
    }

    /**
     * Il corpo di una modifica (Todo::modifica): come validaCorpo, e almeno un campo, o 422 sul corpo intero che elenca i campi che si
     * scrivono qui.
     *
     * @param  array<mixed>  $corpo
     * @param  array<string, mixed>  $regole
     * @param  list<string>  $scrivibili
     * @return array<string, mixed>
     */
    private function modificaTodo(array $corpo, array $regole, array $scrivibili): array
    {
        $campi = $this->testi->validaCorpo($corpo, $regole);

        if ($campi === []) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.almeno_un_campo', ['campi' => implode(', ', $scrivibili)]), 'regola' => Regola::OBBLIGATORIO, 'pointer' => '#']]);
        }

        return $campi;
    }

    /**
     * Una regola che rifiuta il campo: 422 `campo_vietato` sul suo pointer, col testo dato.
     *
     * @param  array<string, string>  $parametri
     */
    private function rifiutaIlCampoTodo(string $testo, array $parametri = []): Closure
    {
        return function (string $attributo, mixed $valore, Closure $rifiuta) use ($testo, $parametri) {
            $this->testi->dichiara($attributo, Regola::CAMPO_VIETATO);
            $rifiuta($testo)->translate(['attribute' => $this->testi->nome($attributo), ...$parametri]);
        };
    }

    /** L'ora senza il giorno è 422 `campo_vietato` su `#/ora`. */
    private function oraSenzaGiornoTodo(?string $giorno, ?string $ora): void
    {
        if ($ora !== null && $giorno === null) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.todo_ora_senza_giorno'), 'regola' => Regola::CAMPO_VIETATO, 'pointer' => '#/ora']]);
        }
    }

    /** Se la lista ha già mille aperti (i fatti non contano): 409 `limite_raggiunto` con `limite`. */
    private function tettoDegliApertiTodo(string $lista): void
    {
        $aperti = array_filter($this->todoElementi, fn (array $e) => $e['lista'] === $lista && ! $e['archiviato'] && $e['fatto_il'] === null);

        if (count($aperti) >= self::TODO_APERTI_TETTO) {
            throw new Problema('limite_raggiunto', estensioni: ['limite' => self::TODO_APERTI_TETTO]);
        }
    }

    /**
     * I tag senza doppioni che non distinguono le maiuscole (il primo vince), nell'ordine dato.
     *
     * @param  list<string>  $tag
     * @return list<string>
     */
    private function tagSenzaDoppioniTodo(array $tag): array
    {
        $visti = [];
        $salvati = [];

        foreach ($tag as $uno) {
            $chiave = mb_strtolower($uno);

            if (! isset($visti[$chiave])) {
                $visti[$chiave] = true;
                $salvati[] = $uno;
            }
        }

        return $salvati;
    }

    /** L'istante di una scrittura (NelRegistro::istanteDopo, AP6): adesso, e almeno un millisecondo dopo quello che la riga aveva. */
    private function dopoTodo(CarbonImmutable $prima): CarbonImmutable
    {
        $ora = now()->toImmutable()->startOfMillisecond();

        return $ora->lessThanOrEqualTo($prima) ? $prima->addMillisecond() : $ora;
    }

    /** Un tag nella forma con cui si confronta e si conta (Todo::tagPiegato, SomiglianzaDiNomi::piega): senza spazi ai bordi, senza segni, piegato. */
    private static function tagPiegatoTodo(string $tag): string
    {
        $tag = trim($tag);
        $scomposto = \Normalizer::normalize($tag, \Normalizer::FORM_D);

        return mb_convert_case(preg_replace('/\p{Mn}+/u', '', is_string($scomposto) ? $scomposto : $tag) ?? $tag, MB_CASE_FOLD, 'UTF-8');
    }

    /** Oggi, a mezzanotte, nel fuso della persona. */
    private function oggiTodo(string $persona): CarbonImmutable
    {
        return now()->toImmutable()->setTimezone($this->persone[$persona]['fuso_orario'] ?? 'Europe/Rome')->startOfDay();
    }

    /**
     * La lista «Personale» della persona nel workspace; nasce alla prima lettura, col nome della sua lingua.
     *
     * @param  array{persona: string, workspace: string, accesso: string}  $chi
     * @return array<string, mixed>
     */
    private function personaleTodo(array $chi): array
    {
        foreach ($this->todoListe as $lista) {
            if ($lista['workspace'] === $chi['workspace'] && $lista['persona'] === $chi['persona'] && $lista['predefinita']) {
                return $lista;
            }
        }

        $id = self::id();
        $ora = now()->toImmutable()->startOfMillisecond();

        return $this->todoListe[$id] = [
            'id' => $id, 'workspace' => $chi['workspace'], 'persona' => $chi['persona'],
            'nome' => self::TODO_NOMI_PERSONALE[$this->persone[$chi['persona']]['lingua'] ?? 'it'] ?? self::TODO_NOMI_PERSONALE['it'],
            'colore' => 'neutral', 'tipo' => 'personale', 'scheda' => null, 'board' => null, 'predefinita' => true, 'ordine' => 'posizione', 'posizione' => self::TODO_PASSO,
            'archiviata' => false, 'creata_il' => $ora, 'aggiornata_il' => $ora,
        ];
    }

    /**
     * Le liste non archiviate della persona, «Personale» per prima, poi per posizione e id.
     *
     * @param  array{persona: string, workspace: string, accesso: string}  $chi
     * @return list<array<string, mixed>>
     */
    private function listeDellaPersonaTodo(array $chi): array
    {
        $liste = array_values(array_filter($this->todoListe, fn (array $l) => $l['workspace'] === $chi['workspace'] && $l['persona'] === $chi['persona'] && $l['tipo'] === 'personale' && ! $l['archiviata']));
        usort($liste, fn (array $una, array $altra) => [$altra['predefinita'], $una['posizione'], $una['id']] <=> [$una['predefinita'], $altra['posizione'], $altra['id']]);

        return $liste;
    }

    /**
     * Se l'id è una lista non archiviata della persona.
     *
     * @param  array{persona: string, workspace: string, accesso: string}  $chi
     */
    private function haListaTodo(array $chi, string $id): bool
    {
        $lista = $this->todoListe[strtolower($id)] ?? null;

        return $lista !== null && $lista['workspace'] === $chi['workspace'] && ($lista['tipo'] === 'progetto' || $lista['persona'] === $chi['persona']) && ! $lista['archiviata'];
    }

    /**
     * Una lista del percorso: di un altro, di un altro workspace, archiviata o che non c'è è 404.
     *
     * @param  array{persona: string, workspace: string, accesso: string}  $chi
     * @return array<string, mixed>
     */
    private function listaDelPercorsoTodo(array $chi, string $id): array
    {
        $this->personaleTodo($chi);

        return $this->haListaTodo($chi, $id) ? $this->todoListe[strtolower($id)] : throw new Problema('non_trovato');
    }

    /**
     * La lista di un campo del corpo (`lista_id`): una che non è sua, che non c'è o è archiviata è 422 sul campo, mai 404.
     *
     * @param  array{persona: string, workspace: string, accesso: string}  $chi
     * @return array<string, mixed>
     */
    private function listaDelCampoTodo(array $chi, string $id): array
    {
        return $this->haListaTodo($chi, $id)
            ? $this->todoListe[strtolower($id)]
            : throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.lista_del_todo'), 'regola' => Regola::NON_TROVATO, 'pointer' => '#/lista_id']]);
    }

    /**
     * Un to-do del percorso: di un altro, di un altro workspace, eliminato o che non c'è è 404.
     *
     * @param  array{persona: string, workspace: string, accesso: string}  $chi
     * @return array<string, mixed>
     */
    private function elementoDelPercorsoTodo(array $chi, string $id): array
    {
        $elemento = $this->todoElementi[strtolower($id)] ?? null;

        return $elemento !== null && $elemento['workspace'] === $chi['workspace'] && ! $elemento['archiviato'] && $this->haListaTodo($chi, $elemento['lista'])
            ? $elemento
            : throw new Problema('non_trovato');
    }

    /**
     * I to-do non eliminati della persona nel workspace, in liste non archiviate.
     *
     * @param  array{persona: string, workspace: string, accesso: string}  $chi
     * @return list<array<string, mixed>>
     */
    private function elementiDellaPersonaTodo(array $chi): array
    {
        return array_values(array_filter(
            $this->todoElementi,
            fn (array $e) => $e['workspace'] === $chi['workspace'] && $e['persona'] === $chi['persona'] && ! $e['archiviato'] && ! ($this->todoListe[$e['lista']]['archiviata'] ?? false),
        ));
    }

    /**
     * Le liste non archiviate che la persona vede di default: le personali (come listeDellaPersonaTodo) e le `progetto` in cui è coinvolta,
     * cioè quelle che ha creato o in cui ha un to-do assegnato, per data di nascita.
     *
     * @param  array{persona: string, workspace: string, accesso: string}  $chi
     * @return list<array<string, mixed>>
     */
    private function listeCoinvolteTodo(array $chi): array
    {
        $progetto = array_values(array_filter($this->todoListe, fn (array $l) => $l['workspace'] === $chi['workspace'] && $l['tipo'] === 'progetto' && ! $l['archiviata']
            && ($l['persona'] === $chi['persona'] || array_filter($this->todoElementi, fn (array $e) => $e['lista'] === $l['id'] && ! $e['archiviato'] && $e['assegnato'] === $chi['persona']) !== [])));
        usort($progetto, fn (array $una, array $altra) => [self::iso($una['creata_il']), $una['id']] <=> [self::iso($altra['creata_il']), $altra['id']]);

        return [...$this->listeDellaPersonaTodo($chi), ...$progetto];
    }

    /**
     * I to-do non eliminati delle liste che la persona legge (le sue personali e ogni lista `progetto` del workspace), in liste non archiviate.
     *
     * @param  array{persona: string, workspace: string, accesso: string}  $chi
     * @return list<array<string, mixed>>
     */
    private function elementiVisibiliTodo(array $chi): array
    {
        return array_values(array_filter(
            $this->todoElementi,
            fn (array $e) => $e['workspace'] === $chi['workspace'] && ! $e['archiviato'] && $this->haListaTodo($chi, $e['lista']),
        ));
    }

    /** Una regola che rifiuta una `scheda_id` che non è una scheda del workspace (422 `non_trovato` sul parametro o sul campo). */
    private function schedaDelWorkspaceTodo(array $chi): Closure
    {
        return function (string $attributo, mixed $valore, Closure $rifiuta) use ($chi) {
            if (($this->schede[(string) $valore]['workspace'] ?? null) !== $chi['workspace']) {
                $this->testi->dichiara($attributo, Regola::NON_TROVATO);
                $rifiuta('regole.scheda_del_workspace')->translate(['attribute' => $this->testi->nome($attributo)]);
            }
        };
    }

    /**
     * La scheda di un campo del corpo (`scheda_id`): una che non è del workspace o che non c'è è 422 `non_trovato` sul campo, mai 404.
     *
     * @param  array{persona: string, workspace: string, accesso: string}  $chi
     * @return array<string, mixed>
     */
    private function schedaDelCampoTodo(array $chi, string $id): array
    {
        $scheda = $this->schede[$id] ?? null;

        return $scheda !== null && $scheda['workspace'] === $chi['workspace']
            ? $scheda
            : throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.scheda_del_workspace'), 'regola' => Regola::NON_TROVATO, 'pointer' => '#/scheda_id']]);
    }

    /**
     * La lista `progetto` della scheda di un to-do che nasce senza `lista_id`: 422 sul campo se la scheda non c'è o non ha ancora la sua lista.
     *
     * @param  array{persona: string, workspace: string, accesso: string}  $chi
     * @return array<string, mixed>
     */
    private function listaDellaSchedaTodo(array $chi, string $scheda): array
    {
        $this->schedaDelCampoTodo($chi, $scheda);

        return array_values(array_filter($this->todoListe, fn (array $l) => $l['workspace'] === $chi['workspace'] && $l['tipo'] === 'progetto' && $l['scheda'] === $scheda && ! $l['archiviata']))[0]
            ?? throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.todo_scheda_senza_lista'), 'regola' => Regola::NON_TROVATO, 'pointer' => '#/scheda_id']]);
    }

    /**
     * Si può scrivere nella lista: una `progetto` vuole la scheda non archiviata (409 `scheda_archiviata`); una personale non ha niente da
     * controllare. Il finto non modella le colonne né lo stato delle board.
     *
     * @param  array<string, mixed>  $lista
     */
    private function scrivibileTodo(array $lista): void
    {
        if ($lista['tipo'] === 'progetto' && ($this->schede[$lista['scheda']]['archiviata_il'] ?? null) !== null) {
            throw new Problema('scheda_archiviata');
        }
    }

    /** 422 `campo_vietato` sul campo, col testo che dice perché. */
    private function campoVietatoTodo(string $campo, string $testo): Problema
    {
        return new Problema('dati_non_validi', [['detail' => $this->testi->testo($testo, ['attribute' => $campo]), 'regola' => Regola::CAMPO_VIETATO, 'pointer' => "#/{$campo}"]]);
    }

    /** Un membro del workspace (qualunque ruolo): altrimenti 422 `non_trovato` sul campo dato. */
    private function membroTodo(array $chi, string $id, string $campo): string
    {
        if (! isset($this->membri[$chi['workspace']][$id])) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.utente_del_workspace'), 'regola' => Regola::NON_TROVATO, 'pointer' => "#/{$campo}"]]);
        }

        return $id;
    }

    /**
     * La scheda e l'assegnato di un to-do che nasce nella lista data (Todo::legami).
     *
     * @param  array{persona: string, workspace: string, accesso: string}  $chi
     * @param  array<string, mixed>  $lista
     * @param  array<string, mixed>  $campi
     * @return array{scheda: ?string, assegnato: ?string}
     */
    private function legamiTodo(array $chi, array $lista, array $campi): array
    {
        $scheda = isset($campi['scheda_id']) ? (string) $campi['scheda_id'] : null;
        $assegnato = isset($campi['assegnato_a']) ? (string) $campi['assegnato_a'] : null;

        if ($lista['tipo'] === 'progetto') {
            if ($scheda !== null && $scheda !== $lista['scheda']) {
                throw $this->campoVietatoTodo('scheda_id', 'regole.todo_scheda_della_lista');
            }

            return ['scheda' => $lista['scheda'], 'assegnato' => $assegnato === null ? null : $this->membroTodo($chi, $assegnato, 'assegnato_a')];
        }

        if ($assegnato !== null) {
            throw $this->campoVietatoTodo('assegnato_a', 'regole.todo_assegnato_solo_progetto');
        }

        if ($scheda !== null) {
            $this->schedaDelCampoTodo($chi, $scheda);
        }

        return ['scheda' => $scheda, 'assegnato' => null];
    }

    /**
     * I legami che una modifica cambia (Todo::legamiCambiati): `scheda_id` e `assegnato_a`; spostato in una lista `progetto`, il to-do prende
     * la scheda della lista.
     *
     * @param  array{persona: string, workspace: string, accesso: string}  $chi
     * @param  array<string, mixed>  $corrente
     * @param  array<string, mixed>  $campi
     * @return array<string, ?string>
     */
    private function legamiCambiatiTodo(array $chi, array $corrente, array $campi, ?string $arrivo): array
    {
        $lista = $this->todoListe[$arrivo ?? $corrente['lista']];
        $cambi = [];

        if (array_key_exists('scheda_id', $campi)) {
            if ($lista['tipo'] === 'progetto') {
                if ($campi['scheda_id'] !== $lista['scheda']) {
                    throw $this->campoVietatoTodo('scheda_id', 'regole.todo_scheda_della_lista');
                }
            } else {
                if ($campi['scheda_id'] !== null) {
                    $this->schedaDelCampoTodo($chi, (string) $campi['scheda_id']);
                }

                $cambi['scheda'] = $campi['scheda_id'];
            }
        }

        if (array_key_exists('assegnato_a', $campi)) {
            if ($campi['assegnato_a'] !== null) {
                if ($lista['tipo'] !== 'progetto') {
                    throw $this->campoVietatoTodo('assegnato_a', 'regole.todo_assegnato_solo_progetto');
                }

                $this->membroTodo($chi, (string) $campi['assegnato_a'], 'assegnato_a');
            }

            $cambi['assegnato'] = $campi['assegnato_a'];
        }

        if ($lista['tipo'] === 'progetto' && $arrivo !== null) {
            $cambi['scheda'] = $lista['scheda'];
        }

        return $cambi;
    }

    /**
     * Se un to-do è nella lettura chiesta: una vista (i personali della persona e i suoi di progetto, cioè assegnati a lei), una lista
     * (aperti, o fatti con `fatti=true`), una scheda (aperti e fatti) o gli assegnati a chi chiama (di progetto, aperti o fatti con `fatti=true`).
     *
     * @param  array<string, mixed>  $e
     */
    private function inVistaTodo(array $e, ?string $vista, ?string $lista, ?string $scheda, bool $fatti, CarbonImmutable $oggi, string $persona, bool $assegnati = false): bool
    {
        if ($scheda !== null) {
            return $e['scheda'] === $scheda;
        }

        if ($assegnati) {
            return $this->todoListe[$e['lista']]['tipo'] === 'progetto' && $e['assegnato'] === $persona && ($fatti ? $e['fatto_il'] !== null : $e['fatto_il'] === null);
        }

        if ($vista === null) {
            return $e['lista'] === $lista && ($fatti ? $e['fatto_il'] !== null : $e['fatto_il'] === null);
        }

        $dellaLista = $this->todoListe[$e['lista']];

        if ($dellaLista['tipo'] === 'progetto' ? $e['assegnato'] !== $persona : ($dellaLista['persona'] !== $persona || $e['persona'] !== $persona)) {
            return false;
        }

        $giorno = $oggi->format('Y-m-d');

        return match ($vista) {
            'oggi' => $e['fatto_il'] === null && $e['giorno'] !== null && $e['giorno'] <= $giorno,
            'settimana' => $e['fatto_il'] === null && $e['giorno'] !== null && $e['giorno'] >= $oggi->addDay()->format('Y-m-d') && $e['giorno'] <= $oggi->addDays(7)->format('Y-m-d'),
            'tutti' => $e['fatto_il'] === null,
            default => $e['fatto_il'] !== null,
        };
    }

    /**
     * La posizione di un to-do in un ordine, come testi da confrontare uno alla volta (le colonne di TodoLetture::colonne): chiude
     * sempre sull'id; «senza giorno» e «senza ora» stanno in fondo.
     *
     * @param  array<string, mixed>  $e
     * @return list<string>
     */
    private function chiaveTodo(array $e, string $modo): array
    {
        $posizione = sprintf('%012d', $e['posizione']);
        $giorno = $e['giorno'] ?? '9999-12-31';
        $ora = $e['ora'] ?? '99:99';

        return match ($modo) {
            'oggi', 'settimana', 'giorno' => [$giorno, $ora, $posizione, $e['id']],
            'tutti' => [$this->todoListe[$e['lista']]['tipo'] === 'personale' ? '0' : '1', sprintf('%012d', $this->todoListe[$e['lista']]['posizione']), $posizione, $e['id']],
            // Prima la lista `progetto` della scheda, poi i to-do personali collegati.
            'scheda' => [$this->todoListe[$e['lista']]['tipo'] === 'progetto' ? '0' : '1', $posizione, $e['id']],
            // Il più recente per primo: l'istante in millisecondi, capovolto.
            'fatti' => [sprintf('%020d', 99999999999999 - (int) $e['fatto_il']->format('Uv')), $e['id']],
            'colore' => [(string) self::TODO_ORDINE_COLORI[$e['colore']], $posizione, $e['id']],
            default => [$posizione, $e['id']],
        };
    }

    /**
     * Il to-do che sta subito prima nella sua lista (Todo::precedente): quello con la posizione più alta fra le più basse della sua.
     *
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>|null
     */
    private function precedenteTodo(array $e): ?array
    {
        $prima = array_filter($this->todoElementi, fn (array $altro) => $altro['lista'] === $e['lista'] && ! $altro['archiviato'] && $altro['id'] !== $e['id'] && $altro['posizione'] < $e['posizione']);
        usort($prima, fn (array $una, array $altra) => $altra['posizione'] <=> $una['posizione']);

        return $prima[0] ?? null;
    }

    /**
     * La posizione di un to-do in una lista (Posizioni::collocare): a metà fra l'ancora (o l'inizio, o la fine) e il vicino dopo di lei,
     * escludendo `$escludi`. Senza spazio i vicini si rinumerano di un passo alla volta, senza toccare il loro `aggiornato_il`.
     *
     * @param  array<string, mixed>|null  $ancora
     */
    private function posizioneTodo(string $lista, ?string $escludi, ?array $ancora, bool $inTesta, bool $rinumerata = false): int
    {
        $vicini = array_filter($this->todoElementi, fn (array $e) => $e['lista'] === $lista && ! $e['archiviato'] && $e['id'] !== $escludi);
        $valori = array_values(array_map(fn (array $e) => $e['posizione'], $vicini));
        sort($valori);

        if ($ancora !== null) {
            $indice = array_search($this->todoElementi[$ancora['id']]['posizione'], $valori, true);
            $prima = $this->todoElementi[$ancora['id']]['posizione'];
            $dopo = $indice === false ? null : ($valori[$indice + 1] ?? null);
        } elseif ($inTesta) {
            $prima = null;
            $dopo = $valori[0] ?? null;
        } else {
            $prima = $valori === [] ? null : end($valori);
            $dopo = null;
        }

        $nuova = match (true) {
            $prima === null && $dopo === null => self::TODO_PASSO,
            $prima === null => $dopo > 1 ? intdiv($dopo, 2) : null,
            $dopo === null => $prima + self::TODO_PASSO <= self::TODO_SOGLIA ? $prima + self::TODO_PASSO : null,
            default => $dopo - $prima > 1 ? intdiv($prima + $dopo, 2) : null,
        };

        if ($nuova !== null) {
            return $nuova;
        }

        if ($rinumerata) {
            throw new \LogicException('Il To-do: nessuno spazio nemmeno dopo la rinumerazione.');
        }

        uasort($vicini, fn (array $una, array $altra) => $una['posizione'] <=> $altra['posizione']);
        $passo = 0;

        foreach ($vicini as $id => $_) {
            $this->todoElementi[$id]['posizione'] = ++$passo * self::TODO_PASSO;
        }

        return $this->posizioneTodo($lista, $escludi, $ancora === null ? null : $this->todoElementi[$ancora['id']], $inTesta, true);
    }

    /**
     * La posizione in coda a delle posizioni (Posizioni::inCoda).
     *
     * @param  list<int>  $posizioni
     */
    private function inCodaTodo(array $posizioni): int
    {
        return $posizioni === [] ? self::TODO_PASSO : max($posizioni) + self::TODO_PASSO;
    }
}
