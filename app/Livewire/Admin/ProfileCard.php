<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\ProfileService;
use Livewire\Component;

class ProfileCard extends Component
{
    public User $user;

    public string $name = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $savedMessage = null;

    public function mount(): void
    {
        $this->user = auth()->user()->load('tenant');
        $this->name = $this->user->name;
    }

    public function save(ProfileService $profiles): void
    {
        $data = $this->validate($profiles->rules());

        $this->user = $profiles->update($this->user, $data)->load('tenant');

        $this->reset([
            'current_password',
            'password',
            'password_confirmation',
        ]);

        $this->savedMessage = 'Profile updated.';
        session()->flash('status', 'Profile updated.');
    }

    public function render()
    {
        return view('livewire.admin.profile-card');
    }
}
