<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use App\Models\Role;
use App\Models\User;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Laravel\Dusk\Browser;
use Illuminate\Support\Collection;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

/**
 * Browser tests (PR 13): Chrome, headless, against the app served on its own port and database file. Run them with
 * `scripts/dusk.sh` (docs/08): it serves the app, runs `php artisan dusk` and stops the server. `DUSK_CHROME_BINARY`
 * points to a Chrome that matches the installed ChromeDriver (e.g. Chrome for Testing) when there is no system Chrome.
 * Every browser starts signed in as an administrator (Dusk's login route), so the pages and their API calls are allowed.
 */
abstract class DuskTestCase extends BaseTestCase
{
    /**
     * Prepare for Dusk test execution.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        // A page waits for the report's state, built on each request: 5 seconds (Dusk's default) is too short when the
        // machine is busy, and a test then fails on time, not on what it checks.
        \Laravel\Dusk\Browser::$waitSeconds = 10;
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
            // ChromeDriver starts in the background: wait until it answers before the first session.
            $context = stream_context_create(['http' => ['timeout' => 0.5]]);
            for ($i = 0; $i < 50 && @file_get_contents('http://127.0.0.1:9515/status', false, $context) === false; $i++) {
                usleep(100_000);
            }
        }
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
            '--lang=ar',
            // «Reduce motion»: the pages' animations are off (theme.css), so nothing is checked while it is still fading in.
            '--force-prefers-reduced-motion',
            ...(env('CI') ? ['--no-sandbox'] : []),
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());
        if ($binary = env('DUSK_CHROME_BINARY')) {
            $options->setBinary($binary);
        }

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }

    protected function admin(): User
    {
        return User::firstOrCreate(['username' => 'dusk.admin'], [
            'name' => 'مدير الاختبار', 'password' => 'secret123', 'status' => User::ACTIVE,
            'role_id' => Role::firstOrCreate(['is_admin' => true], ['name_ar' => 'مدير النظام', 'open_for_signup' => false])->id,
        ]);
    }

    /** The tests' own requests (postJson, get, …) are made as the same administrator. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->actingAs($this->admin());

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /** Each test signs its browser in again: browsers outlive a test, its database and so its user. One browser per test. */
    public function browse(\Closure $callback): void
    {
        parent::browse(function (Browser $browser) use ($callback) {
            $callback($browser->logout()->loginAs($this->admin()));   // a same-id user of the last test would be kept
        });
    }
}
