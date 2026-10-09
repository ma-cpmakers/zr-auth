<?php

use Zeiras\Auth\Eventi\Firma;

// Il vettore di prova di Standard Webhooks: i valori fissi di TestPayload (libraries/php/tests/TestPayload.php della loro
// repo, letta il 09/10): id, corpo e la chiave in base64. La loro libreria calcola la firma al volo, senza un valore
// scritto: quello atteso qui è stato calcolato a parte con openssl (HMAC-SHA256 di «id.timestamp.corpo» con la chiave
// decodificata, in base64), non con questo codice.
const VETTORE_ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
const VETTORE_ISTANTE = 1614265330;
const VETTORE_CORPO = '{"test": 2432232315}';
const VETTORE_BASE64 = 'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
const VETTORE_FIRMA = 'v1,TW/pFPJ2/LwRQdgfM7WklE9yJiRyMs0cTpVPK8leNAU=';

test('la firma del vettore ufficiale', function () {
    $chiave = base64_decode(VETTORE_BASE64, true);

    expect(Firma::calcola($chiave, VETTORE_ID, VETTORE_ISTANTE, VETTORE_CORPO))->toBe(VETTORE_FIRMA);
});

test('la formula è quella della consegna del backoffice: v1, e il base64 dell’HMAC-SHA256 di id.timestamp.corpo', function () {
    $chiave = random_bytes(32);
    $corpo = '{"id":"evt_1","type":"scheda.creata","data":{}}';

    expect(Firma::calcola($chiave, 'evt_1', 1760000000, $corpo))
        ->toBe('v1,'.base64_encode(hash_hmac('sha256', "evt_1.1760000000.{$corpo}", $chiave, true)));
});

test('verifica: la firma giusta passa, anche fra altre, e una sola basta', function () {
    $chiave = base64_decode(VETTORE_BASE64, true);
    $altra = Firma::calcola(random_bytes(32), VETTORE_ID, VETTORE_ISTANTE, VETTORE_CORPO);

    expect(Firma::verifica($chiave, VETTORE_ID, VETTORE_ISTANTE, VETTORE_CORPO, VETTORE_FIRMA))->toBeTrue()
        ->and(Firma::verifica($chiave, VETTORE_ID, VETTORE_ISTANTE, VETTORE_CORPO, "{$altra} ".VETTORE_FIRMA))->toBeTrue()
        ->and(Firma::verifica($chiave, VETTORE_ID, VETTORE_ISTANTE, VETTORE_CORPO, VETTORE_FIRMA." {$altra}"))->toBeTrue();
});

test('verifica: il corpo, l’id, l’istante o la chiave diversi non passano', function () {
    $chiave = base64_decode(VETTORE_BASE64, true);

    expect(Firma::verifica($chiave, VETTORE_ID, VETTORE_ISTANTE, '{"test":2432232315}', VETTORE_FIRMA))->toBeFalse()
        ->and(Firma::verifica($chiave, 'msg_altro', VETTORE_ISTANTE, VETTORE_CORPO, VETTORE_FIRMA))->toBeFalse()
        ->and(Firma::verifica($chiave, VETTORE_ID, VETTORE_ISTANTE + 1, VETTORE_CORPO, VETTORE_FIRMA))->toBeFalse()
        ->and(Firma::verifica(random_bytes(24), VETTORE_ID, VETTORE_ISTANTE, VETTORE_CORPO, VETTORE_FIRMA))->toBeFalse();
});

test('verifica: una firma con un’altra versione non conta, nemmeno se il resto è quello giusto', function () {
    $chiave = base64_decode(VETTORE_BASE64, true);
    $giusta = substr(VETTORE_FIRMA, 3);

    foreach (["v2,{$giusta}", "v1a,{$giusta}", "v1:{$giusta}", $giusta, "V1,{$giusta}", '', ' ', 'v1,', 'v1'] as $intestazione) {
        expect(Firma::verifica($chiave, VETTORE_ID, VETTORE_ISTANTE, VETTORE_CORPO, $intestazione))->toBeFalse();
    }

    expect(Firma::verifica($chiave, VETTORE_ID, VETTORE_ISTANTE, VETTORE_CORPO, "v2,{$giusta} ".VETTORE_FIRMA))->toBeTrue();
});

test('il confronto è a tempo costante: hash_equals, mai === sulla firma', function () {
    $sorgente = file_get_contents(__DIR__.'/../../src/Eventi/Firma.php');

    expect($sorgente)->toContain('hash_equals(')
        ->and($sorgente)->not->toMatch('/\$attesa\s*[=!]==?|[=!]==?\s*\$attesa/');
});

test('il segreto: whsec_ e da 24 a 64 byte in base64, altrimenti non c’è chiave', function () {
    $con = fn (int $byte) => 'whsec_'.base64_encode(str_repeat('a', $byte));

    expect(Firma::chiave($con(24)))->toBe(str_repeat('a', 24))
        ->and(Firma::chiave($con(64)))->toBe(str_repeat('a', 64))
        ->and(Firma::chiave($con(23)))->toBeNull()
        ->and(Firma::chiave($con(65)))->toBeNull()
        ->and(Firma::chiave(base64_encode(str_repeat('a', 32))))->toBeNull()
        ->and(Firma::chiave('whsec_non base64 !!'))->toBeNull()
        ->and(Firma::chiave('whsec_'))->toBeNull()
        ->and(Firma::chiave(''))->toBeNull()
        ->and(Firma::chiave(null))->toBeNull()
        ->and(Firma::chiave(12345))->toBeNull();
});
