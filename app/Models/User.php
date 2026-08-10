<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable, HasRoles, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'status',
        'branch_id',
        'designation',
        'member_code',
        'invested',
        'locale',
        'currency_code',
        'theme',
        'avatar_path',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Who may log into the back-office panel: any active staff member with a role. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === 'active' && $this->roles()->exists();
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /** Head-office owner — sees and manages everything across all branches. */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * A distributor / dealer (Zonal, District, Taluk, Wholesaler, Reseller, Area Dealer)
     * who logs into the admin panel as a "semi-admin" scoped to their own branch.
     * Legacy equivalent: a ci_users row with role 5 and a desiname, i.e. not admin_id 1.
     */
    public function isDistributor(): bool
    {
        return $this->hasRole('distributor');
    }

    /**
     * Support-desk staff — the most restricted back-office persona. Sees ONLY the
     * Support & Track group (tickets, support chat, track screens); every other
     * screen is hidden and direct-URL access is rejected.
     */
    public function isSupport(): bool
    {
        return $this->hasRole('support') && ! $this->hasRole('super_admin');
    }

    /** The member record this distributor login is mapped to (legacy ci_users.mapid). */
    public function memberAccount()
    {
        return $this->belongsTo(Member::class, 'member_code', 'member_code');
    }

    /**
     * Stock order ceiling = max(member BV, invested) — the legacy admin/Master.php rule.
     * Returns 0.0 when neither is on record (callers treat 0 as "no limit configured").
     */
    public function orderLimit(): float
    {
        $bv = (float) ($this->memberAccount?->bv ?? 0);

        return max($bv, (float) $this->invested);
    }
}
