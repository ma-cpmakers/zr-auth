<?php

namespace Zeiras\Auth;

use Illuminate\Database\Eloquent\Model;

/**
 * Una persona di zr-home come la conosce il modulo: l'id è il `sub` dell'id_token, email, nome e lingua si ricopiano a
 * ogni ingresso. È di zr-home: il modulo non la crea né la cambia da sé.
 *
 * @property int $id
 * @property string $email
 * @property string|null $name
 * @property string|null $locale
 */
class Persona extends Model
{
    protected $table = 'zr_persone';

    public $incrementing = false;

    protected $keyType = 'int';
}
