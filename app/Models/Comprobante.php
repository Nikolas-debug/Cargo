<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** La foto o el PDF del comprobante de un pago, en base64. */
class Comprobante extends Model
{
    protected $fillable = ['anticipo_id', 'nombre', 'mime', 'tamano', 'contenido'];

    protected $hidden = ['contenido'];

    public function anticipo(): BelongsTo
    {
        return $this->belongsTo(Anticipo::class);
    }
}
