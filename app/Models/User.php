<?php

namespace App\Models;

use App\Enums\Role;
use App\Notifications\QueuedResetPassword;
use App\Notifications\QueuedVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Throwable;

/**
 * The role isn't mass assignable, so no form's input can make someone an admin: it is set with forceFill() only
 * where an admin chose it.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $rejected_at
 * @property string $password
 * @property string|null $researcher_id
 * @property string|null $mobile
 * @property int|null $department_id
 * @property Role $role
 * @property string|null $profile_image
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property array<string, bool>|null $email_preferences
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
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'password' => 'hashed',
            'email_preferences' => 'array',
            'role' => Role::class,
        ];
    }

    /**
     * The kinds of notice a member can choose not to get by email. Notices always arrive in the portal; account and
     * Google Drive notices are always emailed too.
     */
    public const EMAIL_KINDS = [
        'reminders' => 'Deadline reminders',
        'reviews' => 'Concept proposal decisions',
        'dates' => 'Project date changes',
    ];

    /**
     * The open work behind the sidebar badges and the phone menu dot, with the hover titles that go with them. The
     * sidebar draws it on load and asks for it again while the tab stays open; faculty never get the admins' counts.
     *
     * @return array{toReview: int, toRevise: int, toApprove: int, unread: int, reviewLabel: string, revisionLabel: string, approveLabel: string|null, unreadLabel: string|null, driveIssue: string|null, announcements: string}
     */
    public function openWork(): array
    {
        $admin = $this->isAdmin();
        $toReview = $admin ? Submission::where('awaiting_review', true)->count() : 0;
        $toRevise = $this->isFaculty() ? Submission::involving($this)->where('awaiting_review', false)->where('concept_passed', false)->count() : 0;
        $toApprove = $admin ? self::pendingApproval()->count() : 0;
        $unread = $this->unreadNotifications()->count();

        return [
            'toReview' => $toReview,
            'toRevise' => $toRevise,
            'toApprove' => $toApprove,
            'unread' => $unread,
            'reviewLabel' => Submission::reviewQueueLabel($toReview),
            'revisionLabel' => trans_choice('{1} 1 project needs revision|[2,*] :count projects need revision', $toRevise),
            // Null with nothing waiting, so no stray "0 …" title is left on the item.
            'approveLabel' => $toApprove ? trans_choice('{1} 1 sign-up to approve|[2,*] :count sign-ups to approve', $toApprove) : null,
            'unreadLabel' => $unread ? trans_choice('{1} 1 unread notification|[2,*] :count unread notifications', $unread) : null,
            // Only admins can fix Google Drive, so only they see what's wrong with it.
            'driveIssue' => $admin ? GoogleDriveConnection::issue() : null,
            'announcements' => Announcement::version(),
        ];
    }

    /**
     * Whether this member wants a kind of notice by email too. Every kind is on until they turn it off.
     */
    public function wantsEmail(string $kind): bool
    {
        return (bool) ($this->email_preferences[$kind] ?? true);
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
        return Publication::shortName($this->displayName());
    }

    /**
     * The full name as messages print it: a name registered in capitals is put in title case, so it doesn't shout.
     */
    public function displayName(): string
    {
        return $this->name === mb_strtoupper($this->name) ? Str::title($this->name) : $this->name;
    }

    /**
     * Give every new account a researcher ID unless one was set explicitly.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->researcher_id ??= static::nextResearcherId();
        });

        // A paper's printed author list holds names, so it follows a rename, whichever page made it.
        static::updated(function (User $user): void {
            if ($user->wasChanged('name')) {
                $user->publications()->get()->each(fn (Publication $paper) => $paper->renameAuthor($user->getPrevious()['name'], $user->name));
            }
        });
    }

    /**
     * Make an account, trying again if another one took its researcher ID between reading the last ID and saving, as
     * when a college signs up together. The callback builds a new User each time, so each try picks a new ID.
     *
     * @param  callable(): self  $create
     */
    public static function createRetryingResearcherId(callable $create): self
    {
        return retry(3, $create, 100, fn (Throwable $e) => $e instanceof UniqueConstraintViolationException && str_contains($e->getMessage(), 'researcher_id'));
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
     * Send the verification email through the queue, so the page that asked for it never waits on Gmail.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new QueuedVerifyEmail);
    }

    /**
     * Send the password reset email through the queue, so Forgot password never waits on Gmail.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new QueuedResetPassword($token));
    }

    /**
     * URL of the user's profile photo, or null when they have not uploaded one. The photo is on Google Drive, so it
     * opens through the portal; the file name in the URL changes with the photo, so browsers can keep it for good.
     */
    public function profileImageUrl(): ?string
    {
        return $this->profile_image ? route('profile-photo', ['user' => $this, 'v' => basename($this->profile_image)]) : null;
    }

    /**
     * Determine whether the user is a portal administrator.
     */
    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /**
     * Whether the account belongs to the Research Office, as an admin or as staff.
     */
    public function isResearchOffice(): bool
    {
        return $this->role === Role::Admin || $this->role === Role::Staff;
    }

    /**
     * Whether the account is a proponent's.
     */
    public function isFaculty(): bool
    {
        return $this->role === Role::Faculty;
    }

    /**
     * Whether an admin let the account in. Sign-ups start without approval; admin-made and seeded accounts are approved.
     */
    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /**
     * Whether an admin turned the sign-up down. The account stays, so the same email can't sign up again.
     */
    public function isRejected(): bool
    {
        return $this->rejected_at !== null;
    }

    /**
     * Sign-ups no admin has approved or rejected yet.
     *
     * @param  Builder<self>  $query
     */
    public function scopePendingApproval(Builder $query): void
    {
        $query->whereNull('approved_at')->whereNull('rejected_at');
    }

    /**
     * Accounts an admin let in.
     *
     * @param  Builder<self>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->whereNotNull('approved_at');
    }

    /**
     * Admins, who hear of work only an admin can fix, such as Google Drive problems and failed emails.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAdmins(Builder $query): void
    {
        $query->where('role', Role::Admin);
    }

    /**
     * The Research Office's accounts, admins and staff, who hear of finished and late projects.
     *
     * @param  Builder<self>  $query
     */
    public function scopeResearchOffice(Builder $query): void
    {
        $query->whereIn('role', [Role::Admin, Role::Staff]);
    }

    /**
     * Faculty accounts, the proponents and authors.
     *
     * @param  Builder<self>  $query
     */
    public function scopeFaculty(Builder $query): void
    {
        $query->where('role', Role::Faculty);
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
