<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * "View as Dealer" — legacy admin/auth/viewasbranch + revert. The super-admin can
 * step into a distributor's (semi-admin) session to see exactly what they see, then
 * step back out. The original admin id is stashed in the session and restored on leave.
 *
 * Filament's AuthenticateSession middleware fingerprints the logged-in user's password
 * hash, so after swapping users we must refresh that fingerprint or the very next panel
 * request would log us out.
 */
class ImpersonationController extends Controller
{
    /** Begin impersonating $user. Only the super-admin may do this. */
    public function start(User $user): RedirectResponse
    {
        $current = Auth::user();

        abort_unless($current && $current->isSuperAdmin(), 403);
        abort_if($user->isSuperAdmin(), 403, 'Cannot view as another super-admin.');
        abort_unless($user->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')), 403, 'This account cannot log in.');

        session(['impersonator_id' => $current->getKey()]);

        Auth::login($user);
        $this->refreshSessionFingerprint($user);

        return redirect('/admin');
    }

    /** Step back out to the original super-admin. */
    public function leave(): RedirectResponse
    {
        $originalId = session('impersonator_id');

        if ($originalId && $original = User::find($originalId)) {
            Auth::login($original);
            $this->refreshSessionFingerprint($original);
        }

        session()->forget('impersonator_id');

        return redirect('/admin');
    }

    /** Keep AuthenticateSession's password-hash check happy after a user swap. */
    protected function refreshSessionFingerprint(User $user): void
    {
        $guard = Auth::getDefaultDriver();
        session()->put('password_hash_'.$guard, $user->getAuthPassword());
    }
}
