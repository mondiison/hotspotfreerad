<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ProfileService
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ];
    }

    public function currentPasswordRule(): array
    {
        return ['required', 'current_password'];
    }

    public function update(User $user, array $data): User
    {
        $user->name = $data['name'];

        if (filled($data['password'] ?? null)) {
            $user->password = Hash::make($data['password']);
            $user->must_change_password = false;
        }

        $user->save();

        return $user->refresh();
    }
}
