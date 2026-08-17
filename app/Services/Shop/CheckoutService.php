<?php

namespace App\Services\Shop;

use App\Models\Customer;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Notifications\AppNotification;
use App\Support\Translatable;
use Illuminate\Support\Facades\DB;

/**
 * Storefront checkout for the mobile API. The client sends only product ids + quantities;
 * every price is recomputed server-side from Product::priceBreakup (live rate + charges +
 * GST) — the cart's money is never trusted. Mirrors the web CartController pricing.
 *
 * Orders are INR (members operate in INR); foreign-currency checkout is a later concern.
 */
class CheckoutService
{
    /**
     * Price a cart of [{product_id, qty}] without persisting.
     *
     * @return array{lines: array<int, array>, subtotal: float, tax: float, total: float}
     */
    public function price(array $items): array
    {
        $lines = [];
        $subtotal = 0.0;
        $tax = 0.0;

        foreach ($items as $item) {
            $product = Product::where('is_active', true)->find($item['product_id'] ?? null);
            abort_unless($product, 422, 'A product in your cart is no longer available.');

            $qty = max(1, (int) ($item['qty'] ?? 1));
            $breakup = $product->priceBreakup();
            $unit = (float) $breakup['subtotal'];          // ex-GST unit price
            $lineEx = round($unit * $qty, 2);
            $lineTax = round($lineEx * (float) $breakup['gst_pct'] / 100, 2);

            $lines[] = [
                'product_id' => $product->id,
                'name' => Translatable::pick($product->name) ?: $product->code,
                'qty' => $qty,
                'unit_price' => $unit,
                'gst_pct' => (float) $breakup['gst_pct'],
                'line_total' => $lineEx,
            ];
            $subtotal += $lineEx;
            $tax += $lineTax;
        }

        return [
            'lines' => $lines,
            'subtotal' => round($subtotal, 2),
            'tax' => round($tax, 2),
            'total' => round($subtotal + $tax, 2),
        ];
    }

    /** Place an order for the given identity (member or customer) + delivery details. */
    public function place(array $items, array $customer, ?int $memberId = null, ?int $customerId = null): Order
    {
        abort_if(empty($items), 422, 'Your cart is empty.');
        $priced = $this->price($items);

        return DB::transaction(function () use ($priced, $customer, $memberId, $customerId) {
            $order = Order::create([
                'member_id' => $memberId,
                'customer_id' => $customerId,
                'order_no' => 'ORD-' . str_pad((string) ((int) Order::max('id') + 1), 6, '0', STR_PAD_LEFT),
                'customer_name' => $customer['customer_name'],
                'email' => $customer['email'] ?? null,
                'phone' => $customer['phone'],
                'address' => $customer['address'],
                'city' => $customer['city'],
                'pincode' => $customer['pincode'] ?? null,
                'country' => $customer['country'] ?? 'IN',
                'currency_code' => 'INR',
                'fx_rate' => 1,
                'subtotal' => $priced['subtotal'],
                'tax' => $priced['tax'],
                'shipping' => 0,
                'total' => $priced['total'],
                'status' => 'pending',
                'payment_status' => 'unpaid',
            ]);

            foreach ($priced['lines'] as $line) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $line['product_id'],
                    'name' => $line['name'],
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['line_total'],
                ]);
            }

            $this->notifyBuyer($order, $memberId, $customerId);

            return $order->fresh('items');
        });
    }

    /** Land an "order placed" message in the buyer's app inbox (member or customer). */
    protected function notifyBuyer(Order $order, ?int $memberId, ?int $customerId): void
    {
        $buyer = $memberId ? Member::find($memberId) : ($customerId ? Customer::find($customerId) : null);

        $buyer?->notify(new AppNotification(
            category: 'order',
            title: "Order {$order->order_no} placed",
            body: 'We received your order of ' . \App\Support\Money::group((float) $order->total) . ' INR. We will update you as it progresses.',
            route: "/orders/{$order->id}",
            data: ['order_id' => $order->id, 'order_no' => $order->order_no, 'total' => (float) $order->total],
        ));
    }
}
