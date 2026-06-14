<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Payment\RazorpayService;
use App\Support\Money;
use App\Support\Translatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    public function index()
    {
        return view('storefront.cart', $this->cartData());
    }

    public function add(Request $request, int $id)
    {
        $product = Product::where('is_active', true)->findOrFail($id);
        $qty = max(1, (int) $request->input('qty', 1));

        $b = $product->priceBreakup();
        $cart = session('cart', []);
        $cart[$id] = [
            'name' => Translatable::pick($product->name),
            'price' => $b['subtotal'],          // ex-GST unit price (metal + making + ...)
            'gst_pct' => $b['gst_pct'],
            'qty' => ($cart[$id]['qty'] ?? 0) + $qty,
        ];
        session(['cart' => $cart]);

        return redirect()->route('cart.index')->with('success', 'Added to cart.');
    }

    public function update(Request $request)
    {
        $cart = session('cart', []);
        foreach ((array) $request->input('qty', []) as $id => $qty) {
            if (isset($cart[$id])) {
                $qty = (int) $qty;
                $qty > 0 ? $cart[$id]['qty'] = $qty : null;
                if ($qty <= 0) {
                    unset($cart[$id]);
                }
            }
        }
        session(['cart' => $cart]);

        return redirect()->route('cart.index');
    }

    public function remove(int $id)
    {
        $cart = session('cart', []);
        unset($cart[$id]);
        session(['cart' => $cart]);

        return redirect()->route('cart.index');
    }

    public function checkout()
    {
        if (empty(session('cart'))) {
            return redirect()->route('shop')->with('success', 'Your cart is empty.');
        }

        return view('storefront.checkout', $this->cartData());
    }

    public function placeOrder(Request $request)
    {
        $data = $request->validate([
            'customer_name' => 'required|string|max:200',
            'email' => 'required|email',
            'phone' => 'required|string|max:20',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:120',
            'pincode' => 'nullable|string|max:12',
            'country' => 'nullable|string|max:2',
        ]);

        $cart = session('cart', []);
        if (empty($cart)) {
            return redirect()->route('shop');
        }

        $totals = $this->totals($cart);
        $currency = Money::current();
        $fx = (float) (Currency::where('code', $currency)->value('rate_to_base') ?? 1);

        $order = DB::transaction(function () use ($data, $cart, $totals, $currency, $fx) {
            $order = Order::create($data + [
                'order_no' => 'ORD-' . str_pad((string) ((int) Order::max('id') + 1), 6, '0', STR_PAD_LEFT),
                'country' => $data['country'] ?? 'IN',
                'currency_code' => $currency,
                'fx_rate' => $fx,
                'subtotal' => $totals['subtotal'],
                'tax' => $totals['tax'],
                'shipping' => 0,
                'total' => $totals['total'],
                'status' => 'pending',
                'payment_status' => 'unpaid',
            ]);

            foreach ($cart as $id => $row) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $id,
                    'name' => $row['name'],
                    'qty' => $row['qty'],
                    'unit_price' => $row['price'],
                    'line_total' => $row['price'] * $row['qty'],
                ]);
            }

            return $order;
        });

        // Razorpay: create a gateway order and send the customer to the pay page.
        $razorpay = app(RazorpayService::class);
        if ($razorpay->configured() && (float) $order->total > 0) {
            $rzp = $razorpay->createOrder((float) $order->total, $order->order_no, ['order_no' => $order->order_no]);
            if ($rzp) {
                $order->update(['razorpay_order_id' => $rzp['id']]);
                Payment::create([
                    'order_id' => $order->id, 'gateway' => 'razorpay', 'razorpay_order_id' => $rzp['id'],
                    'amount' => $order->total, 'currency' => 'INR', 'status' => 'created',
                    'email' => $order->email, 'contact' => $order->phone,
                ]);
            }

            return redirect()->route('order.pay', $order->order_no);
        }

        // gateway not configured — keep the legacy "order received" behaviour
        session()->forget('cart');

        return redirect()->route('order.confirmation', $order->order_no);
    }

    /** Razorpay Checkout page for an unpaid order. */
    public function pay(string $orderNo)
    {
        $order = Order::with('items')->where('order_no', $orderNo)->firstOrFail();
        if ($order->payment_status === 'paid') {
            return redirect()->route('order.confirmation', $order->order_no);
        }

        $razorpay = app(RazorpayService::class);
        if (! $order->razorpay_order_id && $razorpay->configured() && (float) $order->total > 0) {
            if ($rzp = $razorpay->createOrder((float) $order->total, $order->order_no, ['order_no' => $order->order_no])) {
                $order->update(['razorpay_order_id' => $rzp['id']]);
            }
        }

        return view('storefront.pay', [
            'order' => $order,
            'razorpayKey' => $razorpay->keyId(),
            'amountPaise' => (int) round((float) $order->total * 100),
        ]);
    }

    /** Verify the Razorpay signature, mark the order paid, and store the payment record. */
    public function verify(Request $request)
    {
        $data = $request->validate([
            'order_no' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_order_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);

        $order = Order::where('order_no', $data['order_no'])->firstOrFail();
        $razorpay = app(RazorpayService::class);

        $valid = $data['razorpay_order_id'] === $order->razorpay_order_id
            && $razorpay->verifySignature($data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature']);

        $payment = Payment::firstOrNew(['razorpay_order_id' => $data['razorpay_order_id']]);
        $payment->fill([
            'order_id' => $order->id, 'gateway' => 'razorpay',
            'razorpay_payment_id' => $data['razorpay_payment_id'],
            'razorpay_signature' => $data['razorpay_signature'],
            'amount' => $order->total, 'currency' => 'INR',
        ]);

        if ($valid) {
            $details = $razorpay->fetchPayment($data['razorpay_payment_id']);
            $payment->status = 'paid';
            $payment->method = $details['method'] ?? null;
            $payment->email = $details['email'] ?? $order->email;
            $payment->contact = $details['contact'] ?? $order->phone;
            $payment->meta = $details;
            $payment->save();

            $order->update(['payment_status' => 'paid', 'status' => 'confirmed', 'paid_at' => now()]);
            session()->forget('cart');

            return redirect()->route('order.confirmation', $order->order_no);
        }

        $payment->status = 'failed';
        $payment->save();

        return redirect()->route('order.pay', $order->order_no)
            ->with('error', 'Payment could not be verified. Please try again.');
    }

    public function confirmation(string $orderNo)
    {
        $order = Order::with('items')->where('order_no', $orderNo)->firstOrFail();

        return view('storefront.confirmation', ['order' => $order]);
    }

    protected function cartData(): array
    {
        $cart = session('cart', []);

        return ['cart' => $cart, 'totals' => $this->totals($cart)];
    }

    protected function totals(array $cart): array
    {
        $subtotal = $tax = 0.0;
        foreach ($cart as $row) {
            $line = $row['price'] * $row['qty'];
            $subtotal += $line;
            $tax += $line * ($row['gst_pct'] ?? 0) / 100;
        }

        return [
            'subtotal' => round($subtotal, 2),
            'tax' => round($tax, 2),
            'total' => round($subtotal + $tax, 2),
        ];
    }
}
