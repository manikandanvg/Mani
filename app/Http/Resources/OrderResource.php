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
            // Browser leg of mobile payment: the web pay page (Razorpay Checkout)
            // lazily creates the gateway order on first load, so exposing the URL
            // is all the API needs to make an unpaid order payable from the app.
            'pay_url' => $this->payment_status === 'unpaid'
                && (float) $this->total > 0
                && app(\App\Services\Payment\RazorpayService::class)->configured()
                    ? route('order.pay', $this->order_no)
                    : null,
        ];
    }
}
