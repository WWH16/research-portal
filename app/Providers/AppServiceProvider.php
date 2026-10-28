<?php

namespace App\Providers;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsApproved;
use App\Http\Middleware\EnsureUserIsFaculty;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->registerCsvResponse();

        // Livewire re-runs only these of a page's route middleware on each button click, so a role taken away
        // while the page is open also takes away its actions.
        Livewire::addPersistentMiddleware([EnsureUserIsAdmin::class, EnsureUserIsApproved::class, EnsureUserIsFaculty::class]);
    }

    /**
     * `response()->csv($filename, $header, $rows)`: a streamed CSV download that opens cleanly in Excel.
     * The byte order mark makes Excel read UTF-8, so names like Ñuñez keep their letters, and cells
     * starting with = + - @ get a leading apostrophe so Excel shows them as text instead of running
     * them as formulas (CSV injection), since titles and remarks are typed by users.
     */
    protected function registerCsvResponse(): void
    {
        Response::macro('csv', fn (string $filename, array $header, iterable $rows) => Response::streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w') ?: throw new RuntimeException('Cannot open the CSV output stream.');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, escape: '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($cell) => is_string($cell) && preg_match('/^[=+\-@\t\r]/', $cell) ? "'".$cell : $cell, $row), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
