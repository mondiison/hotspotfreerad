<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class ProfileService
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
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

        if (($data['remove_avatar'] ?? false) && $user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->avatar_path = null;
        }

        if (($data['avatar'] ?? null) instanceof UploadedFile) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }

            $user->avatar_path = $data['avatar']->store('avatars', 'public');
        }

        $user->save();

        return $user->refresh();
    }
}
