<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Member;
use App\Models\Order;
use App\Services\Shop\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cart checkout + order history (Phase 1) for the signed-in identity — an MLM member
 * or a retail customer. The cart lives on the device; the client posts product ids +
 * quantities and the server re-prices everything.
 */
class OrderController extends Controller
{
    public function __construct(protected CheckoutService $checkout) {}

    /** POST /checkout — re-price the cart, place the order for the signed-in identity. */
    public function checkout(Request $request): JsonResource
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'customer_name' => ['required', 'string', 'max:200'],
            'phone' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'pincode' => ['nullable', 'string', 'max:12'],
            'country' => ['nullable', 'string', 'max:2'],
        ]);

        $user = $request->user();
        $isMember = $user instanceof Member;

        $order = $this->checkout->place(
            items: $data['items'],
            customer: $data,
            memberId: $isMember ? $user->id : null,
            customerId: $isMember ? null : $user->id,
        );

        // Backfill a retail customer's profile from their first checkout.
        if (! $isMember) {
            $user->fill(array_filter([
                'name' => $user->name ?: $data['customer_name'],
                'email' => $user->email ?: ($data['email'] ?? null),
                'address' => $user->address ?: $data['address'],
                'city' => $user->city ?: $data['city'],
                'pincode' => $user->pincode ?: ($data['pincode'] ?? null),
            ]))->save();
        }

        return (new OrderResource($order))->additional(['message' => 'Order placed.']);
    }

    /** POST /checkout/quote — price a cart without placing it (cart review screen). */
    public function quote(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
        ]);

        return response()->json($this->checkout->price($data['items']));
    }

    /** GET /orders — the signed-in identity's orders, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $this->ownedBy($request)->with('items')->latest()->paginate(15);

        return OrderResource::collection($orders);
    }

    /** GET /orders/{order} — one of the signed-in identity's orders. */
    public function show(Request $request, Order $order): JsonResource
    {
        abort_unless($this->owns($request, $order), 404);

        return new OrderResource($order->load('items'));
    }

    /** Orders owned by the authenticated member or customer. */
    protected function ownedBy(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $user = $request->user();
        $column = $user instanceof Member ? 'member_id' : 'customer_id';

        return Order::where($column, $user->id);
    }

    protected function owns(Request $request, Order $order): bool
    {
        $user = $request->user();

        return $user instanceof Member
            ? (int) $order->member_id === (int) $user->id
            : (int) $order->customer_id === (int) $user->id;
    }
}
