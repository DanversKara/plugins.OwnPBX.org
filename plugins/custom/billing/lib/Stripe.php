<?php

declare(strict_types=1);

/**
 * Minimal Stripe REST client (cURL, no SDK) — what billing needs: products, prices, customers,
 * Checkout Sessions, subscriptions, Customer Portal sessions, setup intents, and webhook
 * signature verification. Ported from the old OwnPBX stripe_api.py.
 */
final class BillingStripeError extends \RuntimeException
{
}

final class BillingStripe
{
    public const API_VERSION = '2024-06-20'; // pinned: response shapes stay stable
    private string $base;

    public function __construct(private string $secretKey)
    {
        if ($secretKey === '') {
            throw new BillingStripeError('Stripe is not set up: add your secret key on the Billing plugin page.');
        }
        $this->base = rtrim((string) (getenv('STRIPE_API_BASE') ?: 'https://api.stripe.com'), '/');
    }

    /** 'test' | 'live' | '' from the key prefix. */
    public static function modeOf(string $key): string
    {
        if (preg_match('/^(sk|rk)_test_/', $key)) {
            return 'test';
        }
        if (preg_match('/^(sk|rk)_live_/', $key)) {
            return 'live';
        }
        return '';
    }

    /** ['a' => ['b' => 1], 'l' => [['x' => 2]]] -> a[b]=1&l[0][x]=2, booleans as true/false. */
    public static function encode(array $params, string $prefix = ''): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            $key = $prefix === '' ? (string) $k : "{$prefix}[{$k}]";
            if (is_array($v)) {
                $out = array_merge($out, self::encode($v, $key));
            } elseif ($v === null) {
                continue;
            } elseif (is_bool($v)) {
                $out[] = [$key, $v ? 'true' : 'false'];
            } else {
                $out[] = [$key, (string) $v];
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public function request(string $method, string $path, array $params = [], string $idempotencyKey = ''): array
    {
        $query = implode('&', array_map(static fn(array $p): string => rawurlencode($p[0]) . '=' . rawurlencode($p[1]), self::encode($params)));
        $url = $this->base . $path . ($method === 'GET' && $query !== '' ? '?' . $query : '');
        $headers = ['Stripe-Version: ' . self::API_VERSION];
        if ($idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERPWD => $this->secretKey . ':',
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($raw)) {
            throw new BillingStripeError('Could not reach Stripe: ' . $err);
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new BillingStripeError("Stripe answered {$code} with no data.");
        }
        if ($code >= 400) {
            throw new BillingStripeError('Stripe: ' . (string) ($json['error']['message'] ?? "error {$code}"));
        }
        return $json;
    }

    public function get(string $path, array $params = []): array
    {
        return $this->request('GET', $path, $params);
    }

    public function post(string $path, array $params = [], string $idempotencyKey = ''): array
    {
        return $this->request('POST', $path, $params, $idempotencyKey);
    }

    /**
     * Verify a Stripe-Signature header and return the event. Throws on a bad or old signature.
     * @return array<string, mixed>
     */
    public static function verifyWebhook(string $payload, string $sigHeader, string $secret, int $tolerance = 300, ?int $now = null): array
    {
        if ($secret === '') {
            throw new BillingStripeError('webhook secret not configured');
        }
        $ts = 0;
        $sigs = [];
        foreach (explode(',', $sigHeader) as $item) {
            [$k, $v] = array_pad(explode('=', trim($item), 2), 2, '');
            if ($k === 't') {
                $ts = (int) $v;
            } elseif ($k === 'v1') {
                $sigs[] = $v;
            }
        }
        if ($ts === 0 || abs(($now ?? time()) - $ts) > $tolerance) {
            throw new BillingStripeError('signature timestamp outside tolerance');
        }
        $expected = hash_hmac('sha256', $ts . '.' . $payload, $secret);
        foreach ($sigs as $s) {
            if (hash_equals($expected, $s)) {
                $event = json_decode($payload, true);
                if (!is_array($event)) {
                    throw new BillingStripeError('bad payload');
                }
                return $event;
            }
        }
        throw new BillingStripeError('signature mismatch');
    }
}
