<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A retail storefront customer (public shopper). Phone-OTP + Sanctum token auth,
 * no password. Distinct from the MLM Member; orders carry one or the other.
 */
class Customer extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasApiTokens;
    use Notifiable;        // app inbox (database channel) + FCM push later

    protected $guarded = [];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function deviceTokens()
    {
        return $this->morphMany(DeviceToken::class, 'owner');
    }

    /** Push targets for the FCM notification channel. */
    public function routeNotificationForFcm(): array
    {
        return $this->deviceTokens()->pluck('token')->all();
    }
}
