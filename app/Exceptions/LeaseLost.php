<?php

namespace App\Exceptions;

use Exception;

/** Another worker (or a cancel / prune) took the run over: this worker stops without writing or deleting anything. */
class LeaseLost extends Exception {}
