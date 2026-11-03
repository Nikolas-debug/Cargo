<?php

namespace App\Enums;

enum TipoLiquidacion: string
{
    /** Cuenta de cobro a un consorcio. */
    case Cobro = 'cobro';

    /** Cuenta de pago a un dueño o conductor. */
    case Pago = 'pago';

    /** El crédito con una cantera por el material: se le abona hasta saldar. */
    case Material = 'material';
}
