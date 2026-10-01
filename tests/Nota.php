<?php

namespace Zeiras\Auth\Tests;

use Illuminate\Database\Eloquent\Model;
use Zeiras\Auth\Concerns\DelWorkspace;

/**
 * Un dato del modulo finto, separato per workspace.
 *
 * @property int $workspace_id
 * @property string $testo
 */
class Nota extends Model
{
    use DelWorkspace;

    protected $table = 'note';

    protected $fillable = ['testo', 'workspace_id'];

    public $timestamps = false;
}
