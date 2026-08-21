<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A payment-proof file (receipt / screenshot / bank slip) on a stock order.
 */
class BranchOrderAttachment extends Model
{
    protected $guarded = [];

    public function orderRequest()
    {
        return $this->belongsTo(BranchOrderRequest::class, 'order_request_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Request-host URL (NOT Storage::url/APP_URL) so LAN and live viewers both load it. */
    public function url(): string
    {
        return url('storage/' . ltrim($this->path, '/'));
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
