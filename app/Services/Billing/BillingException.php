<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\Response;
use RuntimeException;

/** An error returned by billing.twsd.me: { "error": { "code", "message", "field" }, "request_id" }. */
class BillingException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $errorCode = null, public readonly int $status = 0, public readonly ?string $field = null, public readonly ?string $requestId = null)
    {
        parent::__construct($message);
    }

    public static function fromResponse(Response $r): self
    {
        return new self(
            (string) ($r->json('error.message') ?? 'Billing request failed (HTTP '.$r->status().').'),
            $r->json('error.code'),
            $r->status(),
            $r->json('error.field'),
            $r->json('request_id'),
        );
    }

    /** A message that is safe to show a customer. */
    public function customerMessage(): string
    {
        return match ($this->errorCode) {
            'domain_limit_reached' => 'All production domains on this license are in use. Deactivate one or buy an extra domain.',
            'domain_in_use' => 'That domain is already active on another license.',
            'move_limit_reached', 'too_many_moves' => 'This license has reached its limit of 3 domain moves in 30 days.',
            'license_suspended' => 'This license is suspended. Contact support.',
            default => $this->status === 422 && $this->getMessage() ? $this->getMessage() : 'The licensing service couldn’t complete that. Please try again.',
        };
    }
}
