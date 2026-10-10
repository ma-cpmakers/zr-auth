<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Validation\Validator;

/**
 * Il validatore di Laravel con i nomi dei campi di NomiDeiCampi, come `App\Http\Problemi\ValidatoreConNomi` del backoffice.
 */
final class ValidatoreConNomi extends Validator
{
    public function getDisplayableAttribute($attribute)
    {
        if (isset($this->customAttributes[$attribute])) {
            return parent::getDisplayableAttribute($attribute);
        }

        return NomiDeiCampi::di((string) $attribute, $this->translator);
    }
}
