<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Organisation;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Development stand-in for billing.twsd.me (WCC_BILLING_ENABLED=false).
 * Mirrors the real API's behaviour: customers are created on the first order,
 * keys are generated "in billing" and revealed on request, domain actions work.
 * State lives in the orders table (keys) and the cache (domain changes).
 */
class LocalBillingClient implements BillingClient
{
    public const DEMO_ORG = 'org_demo_lowtide';

    public function createOrganisation(Organisation $org): ?string
    {
        if (blank($org->country) || blank($org->email)) {
            return null;   // same as billing: waits for the first order
        }
        Log::info('[billing:local] POST /customers', ['org' => $org->id]);

        return 'cus_'.Str::uuid();
    }

    public function updateOrganisation(Organisation $org): void
    {
        Log::info('[billing:local] PATCH /customers', ['customer' => $org->billing_org_id]);
    }

    public function submitOrder(Order $order, ?Invoice $invoice, string $paymentStatus = 'paid'): array
    {
        $org = $order->organisation;
        $customerId = $org->billing_org_id ?: 'cus_'.Str::uuid();
        $licenseId = $order->meta['license_id'] ?? 'lic_'.Str::uuid();
        $number = $order->meta['license_number'] ?? 'LIC-'.Str::upper(Str::random(4));
        $key = 'LDSK-'.collect(range(1, 4))->map(fn () => Str::upper(Str::random(4)))->implode('-');

        Log::info('[billing:local] POST /orders', ['order' => $order->number, 'kind' => $order->meta['billing_kind'] ?? 'new_license', 'payment_status' => $paymentStatus, 'customer' => $customerId, 'license' => $number]);

        return ['customer_id' => $customerId, 'order_id' => 'ord_'.Str::uuid(), 'license_id' => $licenseId, 'license_number' => $number, 'key' => $key, 'status' => $paymentStatus === 'pending' ? 'pending' : 'active'];
    }

    public function confirmPayment(Order $order, Invoice $invoice): bool
    {
        Log::info('[billing:local] confirm payment', ['order' => $order->number]);

        return true;
    }

    public function recordRefund(Order $order, Invoice $invoice): bool
    {
        Log::info('[billing:local] refund → revoke', ['order' => $order->number]);

        return true;
    }

    public function licenses(Organisation $org): array
    {
        $licenses = $org->billing_org_id === self::DEMO_ORG
            ? array_map(fn ($l) => $l + ['billing_id' => 'lic_demo_'.strtolower($l['id']), 'key_masked' => false], Demo::licenses())
            : [];

        foreach ($org->orders()->with('plan')->whereNotNull('billing_license_id')->latest()->get() as $o) {
            $paidAt = $o->paid_at ?? $o->created_at;
            $expires = $o->billing_type === 'annual' ? $paidAt->copy()->addYear() : null;
            $installs = $o->primary_domain ? [[
                'id' => 'act_'.md5($o->number.$o->primary_domain), 'domain' => $o->primary_domain, 'env' => 'production', 'status' => 'pending',
                'ip' => '—', 'version' => 'not installed', 'php' => '—', 'last' => 'never', 'online' => false,
            ]] : [];
            $key = $o->license_key ?? '';
            $licenses[] = [
                'id' => $o->meta['license_number'] ?? $o->billing_license_id,
                'billing_id' => $o->billing_license_id,
                'product' => setting('branding.product_name', 'LabelDesk'),
                'tier' => $o->plan?->name ?? 'License',
                'type' => ucfirst($o->billing_type),
                'key' => $key ? substr($key, 0, 5).'••••-••••-••••-'.substr($key, -4) : '',
                'key_masked' => true,
                'status' => $o->isPaid() ? 'active' : ($o->status === 'refunded' ? 'revoked' : 'pending'),
                'status_reason' => null,
                'domains_used' => count($installs),
                'domains' => ($o->plan?->domains ?? 1) + $o->extra_domains,
                'purchased' => local_time($paidAt)->format('M d, Y'),
                'renews' => $expires ? local_time($expires)->format('M d, Y') : null,
                'days_left' => $expires ? (int) floor(now()->diffInDays($expires)) : null,
                'pending_until' => $o->isPaid() ? null : local_time($o->created_at->copy()->addDays(14))->format('M d, Y'),
                'price' => $o->total,
                'price_currency' => $o->currency,
                'auto_renew' => $o->billing_type === 'annual',
                'support_until' => local_time($paidAt->copy()->addYear())->format('M d, Y'),
                'support_expired' => false,
                'installations' => $installs,
            ];
        }

        // Apply domain changes made through the portal (local only).
        $changes = Cache::get($this->changesKey($org), []);
        foreach ($licenses as &$l) {
            $id = $l['billing_id'];
            foreach ($changes[$id]['removed'] ?? [] as $removedDomain) {
                $l['installations'] = array_values(array_filter($l['installations'], fn ($i) => $i['domain'] !== $removedDomain));
            }
            foreach ($changes[$id]['added'] ?? [] as $a) {
                $l['installations'][] = $a;
            }
            $l['domains_used'] = count(array_filter($l['installations'], fn ($i) => $i['env'] === 'production'));
            if (isset($changes[$id]['auto_renew'])) {
                $l['auto_renew'] = $changes[$id]['auto_renew'];
            }
        }

        return $licenses;
    }

    public function revealKey(Organisation $org, string $licenseId, ?User $actor = null): string
    {
        if ($key = $org->orders()->where('billing_license_id', $licenseId)->first()?->license_key) {
            return $key;
        }
        $demo = collect(Demo::licenses())->first(fn ($l) => 'lic_demo_'.strtolower($l['id']) === $licenseId);

        return $demo['key'] ?? throw new BillingException('License not found.', 'license_not_found', 404);
    }

    public function addActivation(Organisation $org, string $licenseId, string $domain, ?string $environment, ?User $actor = null): array
    {
        $domain = $this->normalise($domain);
        $env = $environment ?: (preg_match('/^(staging|dev)\.|\.(test|local)$|^localhost$/', $domain) ? 'staging' : 'production');
        $license = collect($this->licenses($org))->firstWhere('billing_id', $licenseId) ?? throw new BillingException('License not found.', 'license_not_found', 404);
        if (collect($license['installations'])->contains('domain', $domain)) {
            throw new BillingException('That domain is already active on another license.', 'domain_in_use', 409);
        }
        if ($env === 'production' && $license['domains_used'] >= $license['domains']) {
            throw new BillingException('All production domains are in use.', 'domain_limit_reached', 409);
        }
        $activation = ['id' => 'act_'.Str::uuid(), 'domain' => $domain, 'env' => $env, 'status' => 'pending', 'ip' => '—', 'version' => 'not installed', 'php' => '—', 'last' => 'never', 'online' => false];
        $this->change($org, function (&$c) use ($licenseId, $activation) { $c[$licenseId]['added'][] = $activation; });

        return $activation;
    }

    public function moveActivation(Organisation $org, string $licenseId, string $activationId, string $newDomain, ?User $actor = null): array
    {
        $license = collect($this->licenses($org))->firstWhere('billing_id', $licenseId);
        $old = collect($license['installations'] ?? [])->firstWhere('id', $activationId) ?? throw new BillingException('Activation not found.', 'activation_not_found', 404);
        $this->deactivateActivation($org, $licenseId, $activationId, $actor);

        return $this->addActivation($org, $licenseId, $newDomain, $old['env'], $actor);
    }

    public function deactivateActivation(Organisation $org, string $licenseId, string $activationId, ?User $actor = null): void
    {
        $license = collect($this->licenses($org))->firstWhere('billing_id', $licenseId);
        $domain = collect($license['installations'] ?? [])->firstWhere('id', $activationId)['domain']
            ?? collect($license['installations'] ?? [])->firstWhere('domain', $activationId)['domain'] ?? null;
        if (! $domain) {
            throw new BillingException('Activation not found.', 'activation_not_found', 404);
        }
        $this->change($org, function (&$c) use ($licenseId, $domain) {
            $c[$licenseId]['added'] = array_values(array_filter($c[$licenseId]['added'] ?? [], fn ($a) => $a['domain'] !== $domain));
            $c[$licenseId]['removed'][] = $domain;
        });
    }

    public function requestCheckIn(Organisation $org, string $licenseId, string $activationId, ?User $actor = null): void
    {
        Log::info('[billing:local] check-in requested', ['license' => $licenseId, 'activation' => $activationId]);
    }

    public function setAutoRenew(Organisation $org, string $licenseId, bool $autoRenew, ?User $actor = null): void
    {
        $this->change($org, function (&$c) use ($licenseId, $autoRenew) { $c[$licenseId]['auto_renew'] = $autoRenew; });
    }

    public function forget(Organisation $org): void
    {
        // nothing cached locally besides portal changes
    }

    private function change(Organisation $org, callable $fn): void
    {
        $c = Cache::get($this->changesKey($org), []);
        $fn($c);
        Cache::forever($this->changesKey($org), $c);
    }

    private function changesKey(Organisation $org): string
    {
        return "billing.local.changes.{$org->id}";
    }

    private function normalise(string $domain): string
    {
        $d = strtolower(trim($domain));
        $d = preg_replace('#^[a-z]+://#', '', $d);
        $d = preg_replace('#[/:?].*$#', '', $d);

        return preg_replace('/^www\./', '', $d);
    }
}
