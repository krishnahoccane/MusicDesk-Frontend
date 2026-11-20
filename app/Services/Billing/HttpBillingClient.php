<?php

namespace App\Services\Billing;

use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Live client for billing.twsd.me — storefront API at /api/v1/storefront
 * (TWSD Billing docs/LICENSING.md; mirrored in docs/billing-integration.md).
 */
class HttpBillingClient implements BillingClient
{
    public const PRODUCT = 'labeldesk';

    public function __construct(private array $config) {}

    // ------------------------------------------------------------------ HTTP

    private function http(?Organisation $org = null, ?User $actor = null): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->config['url'], '/'))
            ->withToken((string) $this->config['key'])
            ->acceptJson()->asJson()->timeout(15)
            // Retry network errors and 5xx only; 4xx are real answers.
            ->retry(2, 300, fn ($e) => ! ($e instanceof \Illuminate\Http\Client\RequestException) || $e->response->serverError(), throw: false)
            ->withHeaders(array_filter([
                'X-Customer-Id' => $org?->billing_org_id,
                // Billing records this on every change; allowed chars [A-Za-z0-9_-@.], max 100.
                'X-Acting-User' => $actor ? Str::limit(preg_replace('/[^A-Za-z0-9_\-@.]/', '', $actor->email), 100, '') : null,
            ]));
    }

    private function ok(Response $r): Response
    {
        if ($r->failed()) {
            throw BillingException::fromResponse($r);
        }

        return $r;
    }

    // ------------------------------------------------------------- Customers

    /** POST /customers at sign-up. Billing requires a country; without one, creation waits for the first order. */
    public function createOrganisation(Organisation $org): ?string
    {
        if (blank($org->country) || blank($org->email)) {
            return null;
        }
        $owner = $org->users()->where('role', 'owner')->first();

        return (string) $this->ok($this->http(null, $owner)
            ->withHeaders(['Idempotency-Key' => 'customer-'.$org->id])
            ->post('/customers', array_filter([
                'company' => $org->name,
                'email' => $org->email,
                'name' => $owner?->name,
                'country' => strtoupper($org->country),
                'vat_id' => $org->vat_id,
                'currency' => $org->currency,
            ])))->json('id');
    }

    public function updateOrganisation(Organisation $org): void
    {
        if (! $org->billing_org_id) {
            return;
        }

        $lines = preg_split('/\R/', trim((string) ($org->address['text'] ?? ''))) ?: [];
        $this->ok($this->http($org)->patch("/customers/{$org->billing_org_id}", array_filter([
            'company' => $org->name,
            'billing_email' => $org->email,
            'country' => $org->country,
            'currency' => $org->currency,
            'address' => $lines && $lines[0] !== '' ? ['line1' => implode(', ', array_map('trim', $lines))] : null,
        ], fn ($v) => $v !== null && $v !== '')));
    }

    // ---------------------------------------------------------------- Orders

    public function submitOrder(Order $order, ?Invoice $invoice, string $paymentStatus = 'paid'): array
    {
        $order->loadMissing('organisation', 'plan', 'user');
        $org = $order->organisation;
        $kind = $order->meta['billing_kind'] ?? 'new_license';

        $body = array_filter([
            'kind' => $kind,
            'customer' => $kind === 'new_license' ? ($org->billing_org_id ? ['id' => $org->billing_org_id] : [
                'id' => null,
                'name' => $order->user?->name,
                'email' => $org->email ?: $order->user?->email,
                'company' => $org->name,
                'country' => $org->country,
                'vat_id' => $org->vat_id,
            ]) : null,
            'product' => $kind === 'new_license' ? self::PRODUCT : null,
            'plan_code' => in_array($kind, ['new_license', 'change_plan'], true) ? $order->plan?->code : null,
            'billing_type' => $kind === 'new_license' ? $order->billing_type : null,
            'license_id' => $kind !== 'new_license' ? ($order->meta['license_id'] ?? null) : null,
            'extra_domains' => in_array($kind, ['new_license', 'extra_domains'], true) ? $order->extra_domains : null,
            'primary_domain' => $kind === 'new_license' ? $order->primary_domain : null,
            'auto_renew' => $kind === 'new_license' ? $order->billing_type === 'annual' : null,
            'amount' => ['amount' => $order->amountMinor(), 'currency' => $order->currency],
            'payment_provider' => $order->gateway,
            'payment_reference' => $order->gateway_payment_id ?? $order->number,
            'payment_status' => $paymentStatus,
            'invoice_number' => $invoice?->number,
        ], fn ($v) => $v !== null);

        // Same key + same body replays; the key is fixed per order so retries never duplicate a license.
        $data = $this->ok($this->http($org->billing_org_id ? $org : null, $order->user)
            ->withHeaders(['Idempotency-Key' => 'order-'.$order->number])
            ->post('/orders', $body))->json();

        $this->forget($org);

        return [
            'customer_id' => $data['customer_id'] ?? $org->billing_org_id,
            'order_id' => $data['id'] ?? null,
            'license_id' => $data['license']['id'] ?? $data['license_id'] ?? null,
            'license_number' => $data['license']['number'] ?? null,
            'key' => null,   // never returned by POST /orders; use revealKey()
            'status' => $data['license']['status'] ?? null,
        ];
    }

    /** POST /orders/{ord}/confirm-payment: billing marks the order paid and activates the pending license. */
    public function confirmPayment(Order $order, Invoice $invoice): bool
    {
        return $this->orderAction($order, 'confirm-payment', array_filter([
            'payment_reference' => $order->gateway_payment_id,
            'paid_at' => $order->paid_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ]));
    }

    /** POST /orders/{ord}/refund: billing records the refund and revokes a new license. */
    public function recordRefund(Order $order, Invoice $invoice): bool
    {
        return $this->orderAction($order, 'refund', array_filter([
            'refund_reference' => $order->meta['refund_reference'] ?? null,
            'reason' => $order->meta['refund_reason'] ?? 'Refunded on '.parse_url((string) config('app.url'), PHP_URL_HOST).' (invoice '.$invoice->number.')',
        ]));
    }

    /** Both order actions are idempotent in billing, so retries are safe. Returns false when the order can't be found there. */
    private function orderAction(Order $order, string $action, array $body): bool
    {
        $billingOrderId = $order->meta['billing_order_id'] ?? null;
        if (! $billingOrderId) {
            Log::warning("Billing: order {$order->number} has no billing order id; cannot $action.");

            return false;
        }

        // Recorded in billing's audit log: the person who acted (e.g. the admin confirming a transfer), else the buyer.
        $actor = auth()->user() ?? $order->user;
        $this->ok($this->http($order->organisation, $actor)->post("/orders/$billingOrderId/$action", $body ?: (object) []));
        $this->forget($order->organisation);

        return true;
    }

    // -------------------------------------------------------------- Licenses

    public function licenses(Organisation $org): array
    {
        if (! $org->billing_org_id) {
            return [];
        }

        return Cache::remember($this->cacheKey($org), 60, function () use ($org) {
            $rows = [];
            $cursor = null;
            do {
                $page = $this->ok($this->http($org)->get('/licenses', array_filter(['include' => 'activations', 'limit' => 100, 'cursor' => $cursor])))->json();
                $rows = array_merge($rows, $page['data'] ?? []);
                $cursor = $page['next_cursor'] ?? null;
            } while ($cursor && count($rows) < 1000);

            return array_map(fn ($l) => $this->mapLicense($l), $rows);
        });
    }

    public function revealKey(Organisation $org, string $licenseId, ?User $actor = null): string
    {
        return (string) $this->ok($this->http($org, $actor)->post("/licenses/$licenseId/reveal-key"))->json('key');
    }

    public function addActivation(Organisation $org, string $licenseId, string $domain, ?string $environment, ?User $actor = null): array
    {
        $r = $this->ok($this->http($org, $actor)->withHeaders(['Idempotency-Key' => 'act-'.Str::uuid()])
            ->post("/licenses/$licenseId/activations", array_filter(['domain' => $domain, 'environment' => $environment])));
        $this->forget($org);

        return $r->json();
    }

    public function moveActivation(Organisation $org, string $licenseId, string $activationId, string $newDomain, ?User $actor = null): array
    {
        $r = $this->ok($this->http($org, $actor)->withHeaders(['Idempotency-Key' => 'move-'.Str::uuid()])
            ->post("/licenses/$licenseId/activations/$activationId/move", ['new_domain' => $newDomain]));
        $this->forget($org);

        return $r->json();
    }

    public function deactivateActivation(Organisation $org, string $licenseId, string $activationId, ?User $actor = null): void
    {
        $this->ok($this->http($org, $actor)->delete("/licenses/$licenseId/activations/$activationId"));
        $this->forget($org);
    }

    public function requestCheckIn(Organisation $org, string $licenseId, string $activationId, ?User $actor = null): void
    {
        $this->ok($this->http($org, $actor)->post("/licenses/$licenseId/activations/$activationId/check-in-request"));
        $this->forget($org);
    }

    public function setAutoRenew(Organisation $org, string $licenseId, bool $autoRenew, ?User $actor = null): void
    {
        $this->ok($this->http($org, $actor)->patch("/licenses/$licenseId", ['auto_renew' => $autoRenew]));
        $this->forget($org);
    }

    public function forget(Organisation $org): void
    {
        Cache::forget($this->cacheKey($org));
    }

    private function cacheKey(Organisation $org): string
    {
        return "billing.licenses.{$org->id}";
    }

    /** Billing's license shape (ApiPresenter::license) → the portal view shape. */
    public function mapLicense(array $l): array
    {
        $expires = isset($l['expires_at']) ? Carbon::parse($l['expires_at']) : null;
        $support = isset($l['support_until']) ? Carbon::parse($l['support_until']) : null;
        $days = $expires ? (int) floor(now()->diffInDays($expires, false)) : null;
        $status = $l['status'] ?? 'active';
        $currency = strtoupper($l['price_paid']['currency'] ?? 'USD');
        $decimals = Currency::find($currency)?->decimals ?? 2;

        return [
            'id' => $l['number'] ?? $l['id'],
            'billing_id' => $l['id'],
            'product' => setting('branding.product_name', 'LabelDesk'),
            'tier' => $l['plan_name'] ?? Str::headline((string) ($l['plan_code'] ?? 'License')),
            'type' => ucfirst((string) ($l['billing_type'] ?? 'annual')),
            'key' => $l['key_masked'] ?? '',
            'key_masked' => true,
            'status' => $status === 'active' && $days !== null && $days >= 0 && $days <= 30 ? 'expiring' : $status,
            'status_reason' => $l['status_reason'] ?? null,
            'domains_used' => (int) ($l['domains_used'] ?? 0),
            'domains' => (int) ($l['domains_included'] ?? 0) + (int) ($l['domains_extra'] ?? 0),
            'purchased' => isset($l['purchased_at']) ? local_time($l['purchased_at'])->format('M d, Y') : '—',
            'renews' => $expires && ($l['billing_type'] ?? '') === 'annual' ? local_time($expires)->format('M d, Y') : null,
            'days_left' => $days,
            'pending_until' => isset($l['pending_until']) ? local_time($l['pending_until'])->format('M d, Y') : null,
            'price' => ($l['price_paid']['amount'] ?? 0) / (10 ** $decimals),
            'price_currency' => $currency,
            'auto_renew' => (bool) ($l['auto_renew'] ?? false),
            'support_until' => $support ? local_time($support)->format('M d, Y') : null,
            'support_expired' => $support?->isPast() ?? false,
            'max_version' => $l['max_version'] ?? null,
            'installations' => array_values(array_map(fn ($a) => [
                'id' => $a['id'],
                'domain' => $a['domain'],
                'env' => $a['environment'] ?? 'production',
                'status' => $a['status'] ?? 'active',
                'ip' => $a['server_ip'] ?? '—',
                'version' => $a['version'] ?? 'not installed',
                'php' => $a['php_version'] ?? '—',
                'last' => isset($a['last_check_in_at']) ? local_time($a['last_check_in_at'])->diffForHumans() : 'never',
                'online' => (bool) ($a['online'] ?? false),
            ], $l['activations'] ?? [])),
        ];
    }
}
