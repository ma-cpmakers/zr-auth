<?php

namespace Zeiras\Auth;

use Illuminate\Http\Request;

/**
 * L'IP con cui si contano i freni: l'indirizzo intero per IPv4, il suo /64 per IPv6. Una connessione IPv6 riceve di norma un
 * /64 intero: contando il singolo indirizzo, chi ne ha uno avrebbe miliardi di freni separati. L'IP è quello della richiesta:
 * dietro Cloudflare il modulo deve ricavare quello vero (README).
 */
final class ChiaveIp
{
    public static function di(Request $request): string
    {
        $ip = (string) $request->ip();
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }

        $binario = (string) inet_pton($ip);

        // Un IPv4 scritto come IPv6 (::ffff:203.0.113.7) resta l'IPv4 che è, non il /64 di tutti gli IPv4.
        if (str_starts_with($binario, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($binario, 12));
        }

        return inet_ntop(substr($binario, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
