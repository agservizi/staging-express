<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\DiscountCampaignService;
use App\Services\SalesService;

final class SalesController
{
    public function __construct(
        private SalesService $salesService,
        private DiscountCampaignService $discountCampaignService
    )
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, sale_id?:int, errors?:array<int, string>}
     */
    public function create(int $userId, array $input): array
    {
        $items = $this->buildItems($input);
        if ($items === []) {
            return ['success' => false, 'errors' => ['Aggiungi almeno un articolo.']];
        }

        $vatRate = (float) ($GLOBALS['config']['app']['tax_rate'] ?? 0.0);
        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += (float) $item['price'] * (int) $item['quantity'];
        }

        $campaignId = isset($input['discount_campaign_id']) ? (int) $input['discount_campaign_id'] : 0;
        $campaign = null;
        if ($campaignId > 0) {
            $campaign = $this->discountCampaignService->findActive($campaignId);
            if ($campaign === null) {
                return ['success' => false, 'errors' => ['La campagna sconto selezionata non è più disponibile.']];
            }
        }

        $discount = (float) ($input['discount'] ?? 0);
        if ($campaign !== null) {
            $discount = $this->discountCampaignService->calculateDiscount($campaign, $subtotal);
        }
        if ($discount < 0) {
            $discount = 0.0;
        }
        if ($discount > $subtotal) {
            $discount = $subtotal;
        }

        $data = [
            'user_id' => $userId,
            'customer_name' => $input['customer_name'] ?? null,
            'items' => $items,
            'payment_method' => $input['payment_method'] ?? 'Contanti',
            'discount' => $discount,
            'discount_campaign_id' => $campaign['id'] ?? null,
            'vat' => $vatRate,
        ];

        try {
            $saleId = $this->salesService->createSale($data);
            return ['success' => true, 'sale_id' => $saleId];
        } catch (\Throwable $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, message?:string, errors?:array<int, string>}
     */
    public function cancel(int $userId, array $input): array
    {
        $saleId = (int) ($input['cancel_sale_id'] ?? 0);
        if ($saleId <= 0) {
            return ['success' => false, 'errors' => ['Inserisci un numero scontrino valido.']];
        }

        $reason = isset($input['cancel_reason']) ? trim((string) $input['cancel_reason']) : null;

        try {
            $this->salesService->cancelSale($saleId, $userId, $reason);
            return ['success' => true, 'message' => 'Scontrino annullato correttamente.'];
        } catch (\Throwable $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{success:bool, message?:string, errors?:array<int, string>}
     */
    public function refund(int $userId, array $input): array
    {
        $saleId = (int) ($input['refund_sale_id'] ?? 0);
        if ($saleId <= 0) {
            return ['success' => false, 'errors' => ['Inserisci un numero scontrino valido.']];
        }

        $note = isset($input['refund_note']) ? trim((string) $input['refund_note']) : null;
        try {
            $items = $this->buildRefundItems($input);
            $payload = $items === [] ? null : $items;
            $this->salesService->refundSale($saleId, $userId, $payload, $note);
            return ['success' => true, 'message' => 'Reso registrato e magazzino aggiornato.'];
        } catch (\Throwable $exception) {
            return ['success' => false, 'errors' => [$exception->getMessage()]];
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *   rows: array<int, array<string, mixed>>,
     *   pagination: array{page:int, per_page:int, total:int, pages:int}
     * }
     */
    public function listSales(array $filters, int $page, int $perPage): array
    {
        return $this->salesService->searchSales($filters, $page, $perPage);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, array<string, mixed>>
     */
    private function buildItems(array $input): array
    {
        $items = [];
        $descriptions = $input['item_description'] ?? [];
        $prices = $input['item_price'] ?? [];
        $quantities = $input['item_quantity'] ?? [];
        $iccids = $input['item_iccid'] ?? [];
        $iccidCodes = $input['item_iccid_code'] ?? [];

        foreach ($descriptions as $index => $description) {
            $desc = trim((string) $description);
            $price = (float) ($prices[$index] ?? 0);
            $quantity = (int) ($quantities[$index] ?? 1);
            $iccidId = $iccids[$index] !== '' ? (int) $iccids[$index] : null;
            $iccidCode = isset($iccidCodes[$index]) ? trim((string) $iccidCodes[$index]) : null;

            if ($desc === '' && $iccidId === null) {
                continue;
            }
            if ($price <= 0) {
                continue;
            }

            $items[] = [
                'description' => $desc === '' ? null : $desc,
                'price' => $price,
                'quantity' => $quantity > 0 ? $quantity : 1,
                'iccid_id' => $iccidId,
                'iccid_code' => $iccidCode,
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<int, array{sale_item_id:int, quantity:int, type:string, note?:string|null}>
     */
    private function buildRefundItems(array $input): array
    {
        $items = [];
        $ids = $input['refund_item_id'] ?? [];
        $quantities = $input['refund_item_quantity'] ?? [];
        $types = $input['refund_item_type'] ?? [];
        $notes = $input['refund_item_note'] ?? [];

        foreach ($ids as $index => $idValue) {
            $itemId = (int) $idValue;
            $quantity = (int) ($quantities[$index] ?? 0);
            if ($itemId <= 0 || $quantity <= 0) {
                continue;
            }

            $typeValue = isset($types[$index]) ? (string) $types[$index] : 'Refund';
            $type = strtoupper($typeValue) === 'CREDIT' ? 'Credit' : 'Refund';
            $note = isset($notes[$index]) ? trim((string) $notes[$index]) : null;

            $items[] = [
                'sale_item_id' => $itemId,
                'quantity' => $quantity,
                'type' => $type,
                'note' => $note,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function loadSaleForRefund(int $saleId): array
    {
        if ($saleId <= 0) {
            return ['success' => false, 'message' => 'Inserisci un numero scontrino valido.'];
        }

        $sale = $this->salesService->getSaleWithItems($saleId);
        if ($sale === null) {
            return ['success' => false, 'message' => 'Scontrino non trovato.'];
        }

        if (($sale['status'] ?? 'Completed') === 'Cancelled') {
            return ['success' => false, 'message' => 'Questo scontrino è annullato e non può essere rimborsato.'];
        }

        $items = [];
        $refundableCount = 0;

        foreach ($sale['items'] as $item) {
            $quantity = (int) ($item['quantity'] ?? 0);
            $refunded = (int) ($item['refunded_quantity'] ?? 0);
            $available = max($quantity - $refunded, 0);

            if ($available > 0) {
                $refundableCount++;
            }

            $items[] = [
                'sale_item_id' => (int) ($item['id'] ?? 0),
                'description' => isset($item['description']) && $item['description'] !== null
                    ? (string) $item['description']
                    : null,
                'iccid' => isset($item['iccid']) ? (string) $item['iccid'] : null,
                'price' => (float) ($item['price'] ?? 0),
                'quantity' => $quantity,
                'refunded_quantity' => $refunded,
                'available_quantity' => $available,
            ];
        }

        return [
            'success' => true,
            'sale' => [
                'id' => (int) $sale['id'],
                'status' => (string) $sale['status'],
                'total' => (float) $sale['total'],
                'customer_name' => $sale['customer_name'] ?? null,
                'refunded_amount' => (float) ($sale['refunded_amount'] ?? 0),
                'credited_amount' => (float) ($sale['credited_amount'] ?? 0),
                'allow_refund' => $refundableCount > 0,
                'items' => $items,
            ],
        ];
    }
}
