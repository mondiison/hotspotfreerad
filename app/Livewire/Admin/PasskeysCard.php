<?php

namespace App\Livewire\Admin;

use App\Services\SecurityActivityService;
use Illuminate\Support\Collection;
use Laravel\Passkeys\Passkey;
use Livewire\Component;

class PasskeysCard extends Component
{
    public function deletePasskey(int $passkeyId, SecurityActivityService $activity): void
    {
        $passkey = Passkey::query()
            ->where('user_id', auth()->id())
            ->whereKey($passkeyId)
            ->first();

        $deleted = (bool) $passkey?->delete();

        if ($deleted) {
            $activity->log(auth()->user(), 'passkey_deleted', 'Passkey removed.', [
                'passkey_id' => $passkey->id,
                'passkey_name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
            ]);
        }

        $this->dispatch(
            'notify',
            type: $deleted ? 'success' : 'warning',
            text: $deleted ? 'Passkey removed.' : 'Passkey was not found.'
        );
    }

    public function render()
    {
        return view('livewire.admin.passkeys-card', [
            'passkeys' => $this->passkeys(),
        ]);
    }

    private function passkeys(): Collection
    {
        return auth()->user()
            ->passkeys()
            ->latest('last_used_at')
            ->latest('created_at')
            ->get();
    }
}
