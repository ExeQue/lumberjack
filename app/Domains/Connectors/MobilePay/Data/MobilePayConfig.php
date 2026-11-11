<?php

declare(strict_types=1);

namespace App\Domains\Connectors\MobilePay\Data;

/**
 * What a connection keeps to read one Vipps MobilePay ledger, stored encrypted: the sales
 * unit's API keys, and the ledger they settle to with its currency.
 */
final readonly class MobilePayConfig
{
    public function __construct(
        public MobilePayCredentials $credentials,
        public string $ledgerId,
        public string $currency,
    ) {
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $text = fn (string $key): string => is_string($data[$key] ?? null) ? $data[$key] : '';

        return new self(
            new MobilePayCredentials($text('client_id'), $text('client_secret'), $text('subscription_key'), $text('msn')),
            $text('ledger_id'),
            $text('currency'),
        );
    }

    /**
     * @return array{client_id: string, client_secret: string, subscription_key: string, msn: string, ledger_id: string, currency: string}
     */
    public function toArray(): array
    {
        return [
            'client_id' => $this->credentials->clientId,
            'client_secret' => $this->credentials->clientSecret,
            'subscription_key' => $this->credentials->subscriptionKey,
            'msn' => $this->credentials->merchantSerialNumber,
            'ledger_id' => $this->ledgerId,
            'currency' => $this->currency,
        ];
    }
}
