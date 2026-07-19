<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Collection;
use Laravel\Passkeys\Passkey;
use Livewire\Component;

class PasskeysCard extends Component
{
    public function deletePasskey(int $passkeyId): void
    {
        $deleted = Passkey::query()
            ->where('user_id', auth()->id())
            ->whereKey($passkeyId)
            ->delete();

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
