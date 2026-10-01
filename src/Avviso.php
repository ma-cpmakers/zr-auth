<?php

namespace Zeiras\Auth;

use SensitiveParameter;

/**
 * Un avviso di zr-home (voce #979): il `logout_token` del Back-Channel Logout di OpenID Connect, che zr-home manda
 * all'uscita di una sessione (`sid`), quando toglie una persona da un workspace o ne cambia il ruolo, quando una persona
 * cambia o reimposta la password (`sub` e `workspace`; per la password un avviso per ogni suo workspace), quando un
 * workspace disattiva il modulo (`workspace`). Il claim `motivo` dice quale: `uscita`, `membro_rimosso`,
 * `ruolo_cambiato`, `password_cambiata`, `app_disattivata`. Firma, date, emittente e destinatario li verifica Token; qui i
 * controlli del Back-Channel Logout e del contratto con zr-home.
 */
final class Avviso
{
    public const EVENTO = 'http://schemas.openid.net/event/backchannel-logout';

    /** Un avviso vale 5 minuti dalla firma: zr-home lo firma di nuovo a ogni tentativo. */
    private const VALIDITA = 300;

    /**
     * La revoca che l'avviso chiede, se è valido: firmato da zr-home da non più di 5 minuti, per questo client, con
     * l'evento del Back-Channel Logout e senza `nonce`; e con un `sid`, oppure `sub` e `workspace`, oppure il solo
     * `workspace`. Un claim che c'è ma non è valido non allarga la revoca: l'avviso non vale. Null altrimenti.
     *
     * @return array{sid: string|null, sub: int|null, workspace: int|null, motivo: string|null}|null
     */
    public static function revoca(#[SensitiveParameter] string $logoutToken): ?array
    {
        $claims = Token::claims($logoutToken, 'logout+jwt');
        if ($claims === null
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] < now()->getTimestamp() - self::VALIDITA
            || ! is_array($claims['events'][self::EVENTO] ?? null)
            || array_key_exists('nonce', $claims)) {
            return null;
        }

        $sid = is_string($claims['sid'] ?? null) && $claims['sid'] !== '' ? $claims['sid'] : null;
        $sub = Token::id($claims['sub'] ?? null);
        $workspace = is_int($claims['workspace'] ?? null) && $claims['workspace'] > 0 ? $claims['workspace'] : null;
        $motivo = is_string($claims['motivo'] ?? null) && preg_match('/^[a-z_]{1,40}$/', $claims['motivo']) === 1 ? $claims['motivo'] : null;

        return match (true) {
            array_key_exists('sid', $claims) => $sid === null ? null
                : ['sid' => $sid, 'sub' => null, 'workspace' => null, 'motivo' => $motivo],
            array_key_exists('sub', $claims) => $sub === null || $workspace === null ? null
                : ['sid' => null, 'sub' => $sub, 'workspace' => $workspace, 'motivo' => $motivo],
            array_key_exists('workspace', $claims) => $workspace === null ? null
                : ['sid' => null, 'sub' => null, 'workspace' => $workspace, 'motivo' => $motivo],
            default => null,
        };
    }
}
