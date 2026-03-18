<?php

namespace App\Exceptions;

class CatalogImportException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $offerIndex = null,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }
}
