<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * One thing someone did in the portal. The actor's and subject's names are saved with the entry,
 * so it reads the same after the account, project or filing option is renamed or deleted.
 *
 * The page shows each entry in parts, so a long project title never buries what happened: the actor,
 * a short summary(), then the subject() with its change() and note() in their own column.
 *
 * @property array{status?: array{string, string}, role?: string|array{string, string}, password?: true, documents?: list<string>, replaced?: list<string>, changed?: list<string>, values?: array<string, array{string|null, string|null}>, proponents?: array{added?: list<array{string, string}>, removed?: list<array{string, string}>, roles?: list<array{string, string, string}>}, decision?: 'passed'|'returned', remarks?: string, presented?: bool, kind?: string, from?: string, to?: string}|null $properties
 */
#[Fillable(['user_id', 'actor_name', 'action', 'subject_id', 'subject_label', 'properties'])]
class ActivityLog extends Model
{
    use MassPrunable;

    /** How long entries are kept. The daily model:prune run deletes anything older, except project entries. */
    public const KEEP_MONTHS = 12;

    /** The filters on the Activity Log page, keyed by the action prefix each one covers. */
    public const GROUPS = ['auth' => 'Sign-ins and security', 'submission' => 'Projects', 'user' => 'Users', 'filing' => 'Filing options'];

    /** How a project edit names each field it changed, keyed by the column. */
    public const PROJECT_FIELDS = [
        'title' => 'title',
        'abstract' => 'abstract',
        'research_type_id' => 'research type',
        'category_id' => 'category',
        'designation' => 'designation',
        'start_date' => 'starting date',
        'target_date' => 'completion date',
    ];

    /**
     * Entries older than KEEP_MONTHS, for the daily model:prune run. Project entries stay: a project can run
     * for years, and its Drive page shows the whole history.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMonths(self::KEEP_MONTHS))->where('action', 'not like', 'submission.%');
    }

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record an action by the signed-in member, or by $user when the session doesn't have one, such as a sign-in.
     *
     * @param  array<string, mixed>  $properties  Small facts the entry is worded from, never passwords or form input.
     */
    public static function record(string $action, ?Model $subject = null, array $properties = [], ?User $user = null): self
    {
        $user ??= Auth::user();

        return static::create([
            'user_id' => $user?->id,
            'actor_name' => $user?->name,
            'action' => $action,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subject?->getAttribute(match (true) {
                $subject instanceof Department => 'code',
                $subject instanceof Submission => 'title',
                default => 'name',
            }),
            'properties' => $properties ?: null,
        ]);
    }

    /**
     * Two-factor turned off and admin rights handed out: the entries worth a second look.
     */
    public function isWarning(): bool
    {
        return match ($this->action) {
            'auth.two_factor_disabled' => true,
            'user.created' => ($this->properties['role'] ?? null) === 'admin',
            'user.updated' => isset($this->properties['role']),
            default => false,
        };
    }

    /**
     * The day of the entry as people say it: "Today", "Yesterday", "Sep 29", or "Sep 29, 2026" in another year.
     */
    public function day(): string
    {
        return match (true) {
            $this->created_at->isToday() => __('Today'),
            $this->created_at->isYesterday() => __('Yesterday'),
            default => $this->created_at->format($this->created_at->isCurrentYear() ? 'M j' : 'M j, Y'),
        };
    }

    /**
     * Sign-ins and sign-outs: the bulk of the log, folded together on the page so changes stand out.
     */
    public function isRoutine(): bool
    {
        return in_array($this->action, ['auth.login', 'auth.logout'], true);
    }

    /**
     * Whether two neighbouring entries fold into the same run: both routine, on the same day.
     */
    public static function joins(?self $a, self $b): bool
    {
        return $a !== null && $a->isRoutine() && $b->isRoutine() && $a->created_at->isSameDay($b->created_at);
    }

    /**
     * When a folded run happened, oldest to newest, such as "11:37 – 11:38 AM", or one time when it all
     * fell in the same minute. The run is newest first, so the oldest entry is last.
     *
     * @param  Collection<array-key, self>  $run
     */
    public static function runSpan(Collection $run): string
    {
        [$from, $to] = [$run->last()->created_at, $run->first()->created_at];

        return match (true) {
            $from->format('g:i A') === $to->format('g:i A') => $to->format('g:i A'),
            $from->format('A') === $to->format('A') => $from->format('g:i').' – '.$to->format('g:i A'),
            default => $from->format('g:i A').' – '.$to->format('g:i A'),
        };
    }

    /**
     * Sum up a folded run of routine entries, such as "14 sign-ins, 2 sign-outs · 9 people".
     *
     * @param  Collection<array-key, self>  $run
     */
    public static function runSummary(Collection $run): string
    {
        $signIns = $run->where('action', 'auth.login')->count();
        $signOuts = $run->count() - $signIns;

        return implode(', ', array_filter([
            $signIns ? trans_choice('{1} :count sign-in|[2,*] :count sign-ins', $signIns) : null,
            $signOuts ? trans_choice('{1} :count sign-out|[2,*] :count sign-outs', $signOuts) : null,
        ])).' · '.trans_choice('{1} :count person|[2,*] :count people', $run->pluck('user_id')->unique()->count());
    }

    /**
     * What happened, in a few words that follow the actor's name.
     */
    public function summary(): string
    {
        $p = $this->properties ?? [];
        $kind = isset($p['kind']) ? __($p['kind']) : '';

        return match ($this->action) {
            'auth.login' => __('signed in'),
            'auth.logout' => __('signed out'),
            'auth.registered' => __('created an account'),
            'auth.verified' => __('verified their email'),
            'auth.password_reset' => __('reset their password by email link'),
            'auth.password_changed' => __('changed their password'),
            'auth.email_changed' => __('changed their email'),
            'auth.two_factor_enabled' => __('enabled two-factor authentication'),
            'auth.two_factor_disabled' => __('disabled two-factor authentication'),
            'auth.recovery_codes' => __('regenerated their recovery codes'),

            'submission.created' => __('submitted a proposal'),
            'submission.updated' => __('updated a project'),
            'submission.dates_changed' => __('changed a project’s dates'),
            'submission.presented' => ($p['presented'] ?? true)
                ? __('marked a project as presented at the in-house review')
                : __('marked a project as not presented at the in-house review'),
            'submission.reviewed' => match ($p['decision'] ?? null) {
                'passed' => __('passed a concept proposal'),
                'returned' => __('returned a concept proposal for revision'),
                // Saved under the old flow, when a review could cover any document and set the status.
                default => isset($p['status']) ? __('reviewed a project') : __('reviewed a concept proposal'),
            },

            'user.created' => __('added an account'),
            'user.updated' => __('edited an account'),
            'user.deleted' => __('deleted an account'),

            'filing.created' => __('added a :kind', ['kind' => $kind]),
            'filing.updated' => isset($p['from']) ? __('renamed a :kind', ['kind' => $kind]) : __('edited a :kind', ['kind' => $kind]),
            'filing.deleted' => __('deleted a :kind', ['kind' => $kind]),

            default => $this->action,
        };
    }

    /**
     * The project, account or filing option the entry is about.
     */
    public function subject(): ?string
    {
        return $this->subject_label;
    }

    /**
     * Every part of a project edit, joined with " · ": "Changed the title from “A” to “B” · Changed the completion
     * date from Oct 1, 2027 to Oct 1, 2028 · Updated the abstract · Added Juan Cruz as Staff to the proponents ·
     * Removed Co-Leader Ana Cruz from the proponents · Changed Ana Reyes from Staff to Co-Leader · Uploaded the
     * concept proposal".
     *
     * @param  array<string, array{string|null, string|null}>  $values  Old and new title, Y-m-d dates, research type and category, keyed by column.
     * @param  list<string>  $changed  Other columns, such as the abstract, named without values.
     * @param  array{added?: list<array{string, string}>, removed?: list<array{string, string}>, roles?: list<array{string, string, string}>}  $proponents
     * @param  list<string>  $documents
     * @param  list<string>  $replaced  The documents that took the place of an earlier file.
     */
    private function projectEditNote(array $values, array $changed, array $proponents, array $documents, array $replaced): string
    {
        $and = ' '.__('and').' ';
        $date = fn (?string $date) => $date ? Date::parse($date)->format('M j, Y') : null;
        $parts = [];

        foreach ($values as $column => [$from, $to]) {
            [$from, $to] = match ($column) {
                // Quotes mark where a long title starts and ends inside the sentence.
                'title' => [$from === null ? null : '“'.$from.'”', '“'.$to.'”'],
                'start_date', 'target_date' => [$date($from), $date($to)],
                default => [$from, $to],
            };
            $field = self::PROJECT_FIELDS[$column] ?? $column;
            $parts[] = $from
                ? (string) __('Changed the :field from :from to :to', ['field' => $field, 'from' => $from, 'to' => (string) $to])
                : (string) __('Set the :field to :to', ['field' => $field, 'to' => (string) $to]);
        }
        if ($changed) {
            $parts[] = (string) __('Updated the :fields', ['fields' => Arr::join(array_map(fn (string $field) => self::PROJECT_FIELDS[$field] ?? $field, $changed), ', ', $and)]);
        }
        if (isset($proponents['added'])) {
            $parts[] = (string) __('Added :people to the proponents', ['people' => Arr::join(
                array_map(fn (array $added) => __(':name as :role', ['name' => $added[0], 'role' => $added[1]]), $proponents['added']), ', ', $and)]);
        }
        if (isset($proponents['removed'])) {
            $parts[] = (string) __('Removed :people from the proponents', ['people' => Arr::join(
                array_map(fn (array $removed) => __(':role :name', ['name' => $removed[0], 'role' => $removed[1]]), $proponents['removed']), ', ', $and)]);
        }
        foreach ($proponents['roles'] ?? [] as [$name, $from, $to]) {
            $parts[] = (string) __('Changed :name from :from to :to', ['name' => $name, 'from' => $from, 'to' => $to]);
        }
        // Entries saved before replacements were recorded have no list, so they keep reading "Uploaded".
        foreach (['Uploaded the :documents' => array_diff($documents, $replaced), 'Replaced the :documents' => $replaced] as $line => $stages) {
            if ($stages) {
                $parts[] = (string) __($line, ['documents' => Arr::join(
                    array_map(fn (string $stage) => Str::lower(Submission::DOCUMENTS[$stage] ?? $stage), $stages), ', ', $and)]);
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * The remarks a concept proposal review left, kept with the entry since the project only holds the latest ones.
     */
    public function remarks(): ?string
    {
        return $this->action === 'submission.reviewed' ? $this->properties['remarks'] ?? null : null;
    }

    /**
     * Who to name for an entry in a project's history. Concept reviews, date changes and in-house review marks are
     * the Research Office's, so faculty see the office rather than one staff member.
     */
    public function historyActor(): string
    {
        return in_array($this->action, ['submission.reviewed', 'submission.dates_changed', 'submission.presented'], true)
            ? __('Research Office')
            : ($this->actor_name ?? __('Unknown'));
    }

    /**
     * What happened, as a line in the project's own history, where naming the project would only repeat the page.
     * Reviews saved before reviews passed or returned the concept proposal have no decision. Those holding a
     * status could have covered any document, so they read as a project review; the status is never shown,
     * since it follows the uploads now.
     */
    public function historyHeadline(): string
    {
        return match (true) {
            $this->action === 'submission.created' => __('Submitted the proposal'),
            $this->action === 'submission.updated' => __('Updated the project'),
            $this->action === 'submission.dates_changed' => __('Changed the dates'),
            $this->action === 'submission.presented' => ($this->properties['presented'] ?? true)
                ? __('Marked as presented at the in-house review')
                : __('Marked as not presented at the in-house review'),
            ($this->properties['decision'] ?? null) === 'passed' => __('Passed the concept proposal'),
            ($this->properties['decision'] ?? null) === 'returned' => __('Returned the concept proposal for revision'),
            isset($this->properties['status']) => __('Reviewed the project'),
            $this->remarks() !== null => __('Reviewed the concept proposal and left remarks'),
            default => __('Reviewed the concept proposal'),
        };
    }

    /**
     * A project's Drive page, so the subject line can link to it.
     */
    public function subjectUrl(): ?string
    {
        return str_starts_with($this->action, 'submission.') && $this->subject_id ? route('drive.show', $this->subject_id) : null;
    }

    /**
     * What moved from one value to another: a role, email or name.
     *
     * @return array{0: string, 1: string}|null
     */
    public function change(): ?array
    {
        $p = $this->properties ?? [];

        return match (true) {
            $this->action === 'user.updated' && is_array($p['role'] ?? null) => [Str::ucfirst($p['role'][0]), Str::ucfirst($p['role'][1])],
            $this->action === 'auth.email_changed' => [$p['from'] ?? '', $p['to'] ?? ''],
            $this->action === 'filing.updated' && isset($p['from']) => [$p['from'], $this->subject_label ?? ''],
            default => null,
        };
    }

    /**
     * A short fact the change doesn't cover, such as which documents came in, or an empty string.
     */
    public function note(): string
    {
        $p = $this->properties ?? [];

        return match (true) {
            in_array($this->action, ['submission.created', 'submission.updated', 'submission.dates_changed'], true) => $this->projectEditNote($p['values'] ?? [], $p['changed'] ?? [], $p['proponents'] ?? [], $p['documents'] ?? [], $p['replaced'] ?? []),
            $this->action === 'user.created' && is_string($p['role'] ?? null) => __(':role role', ['role' => Str::ucfirst($p['role'])]),
            $this->action === 'user.updated' && isset($p['password']) => __('New password set'),
            // Only a college has more than its name: an edit that kept the code changed the full name.
            $this->action === 'filing.updated' && ! isset($p['from']) => __('Full name changed'),
            default => '',
        };
    }
}
