<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Member extends Model implements AuthenticatableContract
{
    use Authenticatable;   // acts as the mobile API user (token auth, no password)
    use HasApiTokens;
    use Notifiable;        // app inbox (database channel) + FCM push later
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'joined_on' => 'date',
        'dob' => 'date',
        'bv' => 'decimal:2',
        'gbv' => 'decimal:2',
        'unpure_bv' => 'decimal:2',
        'unpure_gbv' => 'decimal:2',
        'pan_verified' => 'boolean',
        'aadhaar_verified' => 'boolean',
        'pan_verified_at' => 'datetime',
        'aadhaar_verified_at' => 'datetime',
    ];

    public function upline() { return $this->belongsTo(Member::class, 'upline_id'); }
    public function referrer() { return $this->belongsTo(Member::class, 'referrer_id'); }
    public function downlines() { return $this->hasMany(Member::class, 'upline_id'); }
    public function rank() { return $this->belongsTo(Rank::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function wallet() { return $this->hasOne(MemberWallet::class); }
    public function employeeProfile() { return $this->hasOne(EmployeeProfile::class); }
    public function bonds() { return $this->hasMany(Bond::class); }
    public function user() { return $this->belongsTo(User::class); }

    public function deviceTokens() { return $this->morphMany(DeviceToken::class, 'owner'); }

    /** Push targets for the FCM notification channel. */
    public function routeNotificationForFcm(): array
    {
        return $this->deviceTokens()->pluck('token')->all();
    }
}
