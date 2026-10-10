<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $researcher_id
 * @property string|null $mobile
 * @property int|null $department_id
 * @property string $role
 * @property string|null $profile_image
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'email',
    'password',
    'researcher_id',
    'mobile',
    'department_id',
    'role',
    'profile_image',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * The name as lists print it, like the publication list: "John Ansley Rocel" reads "JA Rocel". A name
     * registered in capitals is put in title case first, so it doesn't shout beside the rest of the row.
     */
    public function shortName(): string
    {
        $name = $this->name === mb_strtoupper($this->name) ? Str::title($this->name) : $this->name;

        return Publication::shortName($name);
    }

    /**
     * Give every new account a researcher ID unless one was set explicitly.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->researcher_id ??= static::nextResearcherId();
        });
    }

    /**
     * The next researcher ID for the given year, in the form ISU-2026-0001.
     */
    public static function nextResearcherId(?int $year = null): string
    {
        $prefix = 'ISU-'.($year ?? now()->year).'-';

        $last = static::query()
            ->where('researcher_id', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(researcher_id) DESC')
            ->orderByDesc('researcher_id')
            ->value('researcher_id');

        $next = $last === null ? 1 : (int) substr($last, strlen($prefix)) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Public URL of the user's profile photo, or null when they have not uploaded one.
     */
    public function profileImageUrl(): ?string
    {
        return $this->profile_image ? Storage::disk('public')->url($this->profile_image) : null;
    }

    /**
     * Determine whether the user is a portal administrator.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<Submission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /**
     * @return HasMany<Proponent, $this>
     */
    public function proponents(): HasMany
    {
        return $this->hasMany(Proponent::class);
    }

    /**
     * Projects the member is listed on as a proponent, once each however many studies they are in.
     *
     * @return BelongsToMany<Submission, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Submission::class, 'proponents')->distinct();
    }

    /**
     * Papers the member is tagged on as a portal author.
     *
     * @return BelongsToMany<Publication, $this>
     */
    public function publications(): BelongsToMany
    {
        return $this->belongsToMany(Publication::class);
    }

    /**
     * @return HasMany<DriveItem, $this>
     */
    public function driveItems(): HasMany
    {
        return $this->hasMany(DriveItem::class, 'uploaded_by');
    }

    /**
     * @return HasMany<Announcement, $this>
     */
    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class, 'posted_by');
    }

    /**
     * @return HasMany<ActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}
