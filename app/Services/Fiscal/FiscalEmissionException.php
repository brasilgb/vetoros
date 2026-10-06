<?php

namespace App\Services\Fiscal;

use RuntimeException;

/** Regra de negócio que impede a operação fiscal; mensagem segura para o usuário. */
class FiscalEmissionException extends RuntimeException {}
