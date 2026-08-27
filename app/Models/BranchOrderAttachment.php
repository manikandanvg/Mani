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

    /**
     * Auth-guarded streaming URL (board 2026-08-26). Receipts are no longer reached via
     * public/storage — the live server's missing symlink 404'd every receipt — but are
     * served by OrderAttachmentController from whichever disk holds the file. Request-host
     * based (NOT APP_URL) so LAN and live viewers both load it.
     */
    public function url(): string
    {
        return url('admin/order-attachments/' . $this->getKey());
    }

    /** The disk this file was stored on (legacy rows = public; new uploads = private local). */
    public function diskName(): string
    {
        return $this->disk ?: 'public';
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
