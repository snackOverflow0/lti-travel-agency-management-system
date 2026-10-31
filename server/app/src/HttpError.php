<?php
declare(strict_types=1);
namespace LTI;
final class HttpError extends \RuntimeException {
    public array $fields;
    public function __construct(int $status, string $message, array $fields = []) { parent::__construct($message, $status); $this->fields = $fields; }
}
