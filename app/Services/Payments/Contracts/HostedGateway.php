<?php

namespace App\Services\Payments\Contracts;

use App\Services\Payments\ChargeRequest;
use App\Services\Payments\ChargeResult;
use App\Services\Payments\GatewayCredentials;
use Illuminate\Http\Client\RequestException;

/**
 * A single gateway integration, reusable by both the tenant hotspot checkout
 * flow and the platform billing checkout flow -- unlike the older
 * HotspotHostedGateway contract, this one takes credentials and a charge
 * request as plain data rather than a concrete Payment model, so it has no
 * opinion about who's being charged or where the credentials came from.
 */
interface HostedGateway
{
    /**
     * @throws RequestException
     */
    public function initializeCheckout(GatewayCredentials $credentials, ChargeRequest $request): ChargeResult;

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function verifyPayment(GatewayCredentials $credentials, string $reference): array;

    public function isConfigured(GatewayCredentials $credentials): bool;

    public function webhookIsValid(GatewayCredentials $credentials, string $rawBody, ?string $signature): bool;

    /**
     * @param  array<string, mixed>  $response
     */
    public function checkoutUrl(array $response): ?string;
}
