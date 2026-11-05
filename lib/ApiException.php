<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WpManager;

final class ApiException extends \RuntimeException
{
    public function __construct(public readonly string $method, string $message)
    {
        parent::__construct("ISPConfig API {$method}: {$message}");
    }
}
