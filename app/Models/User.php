<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\StaffPermissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;

class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'avatar_path',
        'role',
        'permissions',
        'is_active',
        'notify_by_email',
        'must_change_password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_confirmed_at',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'permissions' => 'array',
            'is_active' => 'boolean',
            'notify_by_email' => 'boolean',
            'must_change_password' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function securityActivities(): HasMany
    {
        return $this->hasMany(SecurityActivity::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isTenantAdmin(): bool
    {
        return $this->role === 'tenant_admin';
    }

    public function isTenantStaff(): bool
    {
        return $this->role === 'tenant_staff';
    }

    public function hasStaffPermission(string $permission): bool
    {
        return $this->isTenantStaff() && in_array($permission, $this->permissions ?? [], true);
    }

    /**
     * Whether this user may reach the given named route. Super admins and
     * tenant admins always can; tenant staff are gated by StaffPermissions'
     * route map (deny by default -- a route missing from that map is
     * unreachable for staff, not implicitly allowed).
     */
    public function canAccessRoute(?string $routeName): bool
    {
        if ($this->isSuperAdmin() || $this->isTenantAdmin()) {
            return true;
        }

        $required = StaffPermissions::forRoute($routeName);

        if ($required === true) {
            return true;
        }

        if ($required === null) {
            return false;
        }

        return $this->hasStaffPermission($required);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return filled($this->two_factor_secret) && filled($this->two_factor_confirmed_at);
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    public function initials(): string
    {
        return str($this->name)
            ->trim()
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part) => str($part)->substr(0, 1)->upper()->toString())
            ->implode('') ?: 'U';
    }
}
