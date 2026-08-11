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

    protected static function booted(): void
    {
        // Welcome notification for app-era joins (board 2026-08-11). Console paths
        // (legacy import, seeders) are excluded so bulk inserts never mass-welcome.
        static::created(function (self $member) {
            if (app()->runningInConsole()) {
                return;
            }
            \Illuminate\Support\Facades\DB::afterCommit(function () use ($member) {
                \App\Services\Push\Notifier::to($member, 'system',
                    'Welcome to the Lord Jeweller family!',
                    'Your distributor ID is ' . $member->member_code
                        . '. Log into the app with your registered mobile to track your schemes, gold QRs and earnings.',
                    route: '/profile',
                );
            });
        });

        // KYC-verified acknowledgements (board 2026-08-11).
        static::updated(function (self $member) {
            if ($member->wasChanged('pan_verified') && $member->pan_verified) {
                \App\Services\Push\Notifier::to($member, 'system',
                    'PAN verified ✓',
                    'Your PAN has been verified successfully' . ($member->pan_verified_name ? ' as ' . $member->pan_verified_name : '') . '.',
                    route: '/profile',
                );
            }
            if ($member->wasChanged('aadhaar_verified') && $member->aadhaar_verified) {
                \App\Services\Push\Notifier::to($member, 'system',
                    'Aadhaar verified ✓',
                    'Your Aadhaar has been verified successfully. Your KYC is up to date.',
                    route: '/profile',
                );
            }
        });
    }

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
