<?php

it('DEBUG', function () {
    [, $gettoni] = studioColCrm();
    $azienda = alFinto('POST', '/v1/crm/organizzazioni', ['nome' => 'Rossi Srl'], $gettoni['carla']);
    $id = $azienda->json('data.id');
    $out = [
        'azienda' => [$azienda->status(), $azienda->json('data.id')],
        'profilo_min' => (function () use ($id, $gettoni) { $r = alFinto('POST', '/v1/crm/profili-fatturazione', ['organizzazione_id' => $id, 'tipo_intestatario' => 'azienda', 'paese' => 'IT'], $gettoni['carla']); return [$r->status(), $r->json()]; })(),
        'catalogo_carla' => (function () use ($gettoni) { $r = alFinto('POST', '/v1/crm/catalogo', ['tipo' => 'servizio', 'nome' => 'X'], $gettoni['carla']); return [$r->status(), $r->json('codice')]; })(),
        'catalogo_anna' => (function () use ($gettoni) { $r = alFinto('POST', '/v1/crm/catalogo', ['tipo' => 'servizio', 'nome' => 'X'], $gettoni['anna']); return [$r->status(), $r->json('codice')]; })(),
    ];
    throw new Exception(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
});
