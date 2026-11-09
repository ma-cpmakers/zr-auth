<?php

namespace Zeiras\Auth\Testing\Finto;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Le preferenze di notifica del finto (#2732, #2733; zr-home #2729): `io.notifiche.preferenze.mostra` e `.modifica`, della persona del gettone, uguali in
 * ogni suo workspace, e «Non voglio più questa email», `notifiche.disiscrizione.crea` e `.verifica.crea`, senza gettone, come IoNotifichePreferenzeController e
 * NotificheDisiscrizioneController del backoffice. Il catalogo dei tipi è quello di App\Notifiche\Catalogo, con l'email solo per le assegnazioni e le menzioni.
 * Il finto non manda email: un test che vuole il link di un tipo lo chiede a `tokenDiDisiscrizione()`; il token è del finto (firmato col suo segreto, nella forma
 * del contratto) e vale solo qui.
 */
trait NotifichePreferenze
{
    /** @var array<string, string> il tipo della notifica e il suo modulo, nell'ordine di App\Notifiche\Catalogo::TIPI */
    private const NOTIFICHE_TIPI = [
        'com.zeiras.app.modificata' => 'workspace',
        'com.zeiras.workspace.creato' => 'workspace',
        'com.zeiras.workspace.modificato' => 'workspace',
        'com.zeiras.workspace.membro.creato' => 'workspace',
        'com.zeiras.workspace.membro.modificato' => 'workspace',
        'com.zeiras.workspace.membro.eliminato' => 'workspace',
        'com.zeiras.board.cartella.creata' => 'board',
        'com.zeiras.board.cartella.modificata' => 'board',
        'com.zeiras.board.cartella.eliminata' => 'board',
        'com.zeiras.board.board.creata' => 'board',
        'com.zeiras.board.board.modificata' => 'board',
        'com.zeiras.board.lista.creata' => 'board',
        'com.zeiras.board.lista.modificata' => 'board',
        'com.zeiras.board.scheda.creata' => 'board',
        'com.zeiras.board.scheda.modificata' => 'board',
        'com.zeiras.board.scheda.eliminata' => 'board',
        'com.zeiras.board.sprint.creato' => 'board',
        'com.zeiras.board.sprint.modificato' => 'board',
        'com.zeiras.board.etichetta.creata' => 'board',
        'com.zeiras.board.etichetta.modificata' => 'board',
        'com.zeiras.board.etichetta.eliminata' => 'board',
        'com.zeiras.board.vista.creata' => 'board',
        'com.zeiras.board.vista.modificata' => 'board',
        'com.zeiras.board.vista.eliminata' => 'board',
        'com.zeiras.crm.persona.creata' => 'crm',
        'com.zeiras.crm.persona.modificata' => 'crm',
        'com.zeiras.crm.pipeline.creata' => 'crm',
        'com.zeiras.crm.pipeline.modificata' => 'crm',
        'com.zeiras.crm.nota.creata' => 'crm',
        'com.zeiras.crm.nota.modificata' => 'crm',
        'com.zeiras.crm.nota.eliminata' => 'crm',
        'com.zeiras.crm.organizzazione.creata' => 'crm',
        'com.zeiras.crm.organizzazione.modificata' => 'crm',
        'com.zeiras.crm.trattativa.creata' => 'crm',
        'com.zeiras.crm.trattativa.modificata' => 'crm',
        'com.zeiras.crm.attivita.creata' => 'crm',
        'com.zeiras.crm.attivita.modificata' => 'crm',
        'com.zeiras.crm.attivita.eliminata' => 'crm',
        'com.zeiras.crm.motivo.creato' => 'crm',
        'com.zeiras.crm.motivo.modificato' => 'crm',
        'crm.persona.assegnata' => 'crm',
        'crm.organizzazione.assegnata' => 'crm',
        'crm.trattativa.assegnata' => 'crm',
        'crm.attivita.assegnata' => 'crm',
        'board.scheda.assegnata' => 'board',
        'board.commento.menzione' => 'board',
        'crm.acquisto.scoperto' => 'crm',
        'crm.scadenza.in_preavviso' => 'crm',
        'crm.scadenze.riepilogo_del_giorno' => 'crm',
        'crm.preventivo.richiesta' => 'crm',
        'bookings.prenotazione.creata' => 'bookings',
        'bookings.prenotazione.spostata' => 'bookings',
        'bookings.prenotazione.annullata' => 'bookings',
        'board.schede.senza_assegnatario' => 'board',
        'board.todo.promemoria' => 'board',
        'board.scheda.scade_domani' => 'board',
        'board.scheda.scade_oggi' => 'board',
        'io.invito.accettato' => 'workspace',
        'board.gate.deciso' => 'board',
        'flow.avviso.saldo_non_basta' => 'flow',
        'flow.avviso.addebito_non_arrivato' => 'flow',
    ];

    /** I tipi che hanno anche il canale `email` (Notifica::ASSEGNATE). */
    private const NOTIFICHE_CON_EMAIL = ['crm.persona.assegnata', 'crm.organizzazione.assegnata', 'crm.trattativa.assegnata', 'crm.attivita.assegnata', 'board.scheda.assegnata', 'board.commento.menzione'];

    private const NOTIFICHE_CANALI = ['app', 'email', 'spenta'];

    /** Le scelte che una chiamata cambia al più. */
    private const NOTIFICHE_SCELTE_AL_MASSIMO = 100;

    /** La forma di un token di disiscrizione (TokenDiDisiscrizione::FORMA). */
    private const NOTIFICHE_FORMA_DEL_TOKEN = '/^[A-Za-z0-9_-]{20,60}\.[A-Za-z0-9_-]{20,220}\.[A-Za-z0-9_-]{43}$/D';

    /** @var array<string, array<string, string>> il canale scelto, per persona e per tipo; chi non ha scelto ha `app` */
    private array $preferenzeDiNotifica = [];

    /**
     * Il token di «Non voglio più questa email» di una persona e di un tipo: tre pezzi in base64url, `<persona>.<tipo>.<firma>`. Non scade e non si consuma.
     * Il segreto è del finto: un token del backoffice vero non vale qui, e viceversa.
     */
    public function tokenDiDisiscrizione(string $persona, string $tipo): string
    {
        $corpo = self::codificaNotifica($persona).'.'.self::codificaNotifica($tipo);

        return $corpo.'.'.self::codificaNotifica(hash_hmac('sha256', $corpo, 'zr-auth-finto-disiscrizione', true));
    }

    /**
     * io.notifiche.preferenze.mostra: il canale di ogni tipo del catalogo, `app` se la persona non ha scelto; `canali` quelli che il tipo ha davvero.
     * Basta un gettone qualsiasi della persona, con o senza workspace.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraPreferenzeDiNotifica(Request $richiesta): array
    {
        return [200, ['data' => $this->preferenzeDiNotificaDi($this->personaDelGettoneNotifiche($richiesta))]];
    }

    /**
     * io.notifiche.preferenze.modifica: `{tipi: [{tipo, canale}]}`, tutto o niente. Un tipo fuori dal catalogo, ripetuto o un canale che il tipo non ha è 422
     * `valore_non_ammesso` sul pointer dell'elemento (`#/tipi/2/tipo`), e non salva niente. Risponde con la forma di `mostra`.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function modificaPreferenzeDiNotifica(Request $richiesta, array $corpo): array
    {
        $persona = $this->personaDelGettoneNotifiche($richiesta);
        $campi = $this->testi->valida($corpo, [
            'tipi' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.self::NOTIFICHE_SCELTE_AL_MASSIMO],
            'tipi.*.tipo' => ['required', 'string', 'distinct', function (string $attributo, mixed $valore, Closure $fail) {
                if (! is_string($valore) || ! isset(self::NOTIFICHE_TIPI[$valore])) {
                    $this->testi->dichiara($attributo, 'valore_non_ammesso');
                    $fail('validation.in')->translate(['attribute' => $this->testi->nome('tipo')]);
                }
            }],
            'tipi.*.canale' => ['required', 'string', Rule::in(self::NOTIFICHE_CANALI), function (string $attributo, mixed $valore, Closure $fail) use ($corpo) {
                $tipo = data_get($corpo, preg_replace('/\.canale$/', '.tipo', $attributo));

                if (is_string($tipo) && isset(self::NOTIFICHE_TIPI[$tipo]) && ! in_array($valore, self::canaliDiNotifica($tipo), true)) {
                    $this->testi->dichiara($attributo, 'valore_non_ammesso');
                    $fail('validation.in')->translate(['attribute' => $this->testi->nome('canale')]);
                }
            }],
        ]);

        foreach ($campi['tipi'] as $scelta) {
            $this->preferenzeDiNotifica[$persona][$scelta['tipo']] = $scelta['canale'];
        }

        return [200, ['data' => $this->preferenzeDiNotificaDi($persona)]];
    }

    /**
     * notifiche.disiscrizione.crea: spegne l'email del tipo del token per la persona del token — il canale `email` torna `app` e la notifica in app resta. `204`
     * sempre, anche se la persona non c'è o il canale era già un altro. Un token che non è nostro è `422` `formato` su `#/token`; il freno è per token e conta prima.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, null}
     */
    private function disiscrivitiDallEmail(array $corpo): array
    {
        $letto = $this->leggiIlTokenDiDisiscrizione($corpo, 'crea');

        if (($this->preferenzeDiNotifica[$letto['persona']][$letto['tipo']] ?? null) === 'email') {
            $this->preferenzeDiNotifica[$letto['persona']][$letto['tipo']] = 'app';
        }

        return [204, null];
    }

    /**
     * notifiche.disiscrizione.verifica.crea: cosa spegne il token, senza spegnere niente: il tipo e il canale `email`.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function verificaLaDisiscrizione(array $corpo): array
    {
        $letto = $this->leggiIlTokenDiDisiscrizione($corpo, 'verifica');

        return [200, ['data' => ['tipo' => $letto['tipo'], 'canale' => 'email']]];
    }

    /** @return array{persona: string, tipo: string} */
    private function leggiIlTokenDiDisiscrizione(array $corpo, string $metodo): array
    {
        $campi = $this->testi->valida($corpo, ['token' => ['bail', 'required', 'string', 'max:300']]);
        $token = (string) $campi['token'];
        $this->frena("notifiche-disiscrizione-{$metodo}:".hash('sha256', $token), self::FRENI['notifiche_disiscrizione_per_token'], self::MINUTO);

        $letto = $this->personaETipoDelToken($token);
        $this->testi->valida($corpo, ['token' => [function (string $attributo, mixed $valore, Closure $fail) use ($letto) {
            if ($letto === null) {
                $this->testi->dichiara($attributo, 'formato');
                $fail('validation.regex')->translate(['attribute' => $this->testi->nome($attributo)]);
            }
        }]]);

        return $letto ?? throw new LogicException('Il token è già stato rifiutato.');
    }

    /** @return array{persona: string, tipo: string}|null null se non è un token del finto, di un'altra forma o con la firma sbagliata */
    private function personaETipoDelToken(string $token): ?array
    {
        if (preg_match(self::NOTIFICHE_FORMA_DEL_TOKEN, $token) !== 1) {
            return null;
        }

        [$persona, $tipo, $firma] = explode('.', $token);

        if (! hash_equals(self::codificaNotifica(hash_hmac('sha256', $persona.'.'.$tipo, 'zr-auth-finto-disiscrizione', true)), $firma)) {
            return null;
        }

        $persona = base64_decode(strtr($persona, '-_', '+/'), true);
        $tipo = base64_decode(strtr($tipo, '-_', '+/'), true);

        return is_string($persona) && is_string($tipo) && isset(self::NOTIFICHE_TIPI[$tipo]) ? ['persona' => $persona, 'tipo' => $tipo] : null;
    }

    private function personaDelGettoneNotifiche(Request $richiesta): string
    {
        return $this->autentica($richiesta)['persona'] ?? throw new LogicException('Un gettone di una persona ha sempre la persona.');
    }

    /** @return array{tipi: list<array{tipo: string, modulo: string, canale: string, canali: list<string>}>} */
    private function preferenzeDiNotificaDi(string $persona): array
    {
        $tipi = [];

        foreach (self::NOTIFICHE_TIPI as $tipo => $modulo) {
            $tipi[] = ['tipo' => $tipo, 'modulo' => $modulo, 'canale' => $this->preferenzeDiNotifica[$persona][$tipo] ?? 'app', 'canali' => self::canaliDiNotifica($tipo)];
        }

        return ['tipi' => $tipi];
    }

    /** @return list<string> */
    private static function canaliDiNotifica(string $tipo): array
    {
        return in_array($tipo, self::NOTIFICHE_CON_EMAIL, true) ? self::NOTIFICHE_CANALI : ['app', 'spenta'];
    }

    /** base64url senza riempimento. */
    private static function codificaNotifica(string $dati): string
    {
        return rtrim(strtr(base64_encode($dati), '+/', '-_'), '=');
    }
}
