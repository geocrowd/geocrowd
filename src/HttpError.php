<?php

namespace Geocrowd;

final class HttpError extends \Exception
{
    public function __construct(int $status, string $message, public readonly array $details = [])
    {
        parent::__construct($message, $status);
    }
}
