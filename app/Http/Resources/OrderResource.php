<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A storefront order for the mobile app, with its line items.
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'currency' => $this->currency_code ?: 'INR',
            'subtotal' => (float) $this->subtotal,
            'tax' => (float) $this->tax,
            'shipping' => (float) $this->shipping,
            'total' => (float) $this->total,
            'customer' => [
                'name' => $this->customer_name,
                'phone' => $this->phone,
                'email' => $this->email,
                'address' => $this->address,
                'city' => $this->city,
                'pincode' => $this->pincode,
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'name' => $i->name,
                'qty' => (int) $i->qty,
                'unit_price' => (float) $i->unit_price,
                'line_total' => (float) $i->line_total,
            ])),
            'placed_at' => optional($this->created_at)->toIso8601String(),
            // Native in-app payment (2026-08-24): when true the app asks
            // POST orders/{id}/payment/intent for Razorpay Checkout options and
            // runs the native SDK — no browser leg.
            'payable' => app(\App\Services\Payment\OrderPaymentService::class)->payable($this->resource),
        ];
    }
}
