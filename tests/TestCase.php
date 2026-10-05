<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase wipes the database. With a cached config (php artisan optimize)
     * phpunit.xml is ignored and the tests would run against the development database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $database = $app['config']->get('database.connections.'.$app['config']->get('database.default').'.database');

        if ($app->environment() !== 'testing' || $database !== 'reko_test') {
            throw new RuntimeException(
                "Test fermati: userebbero il database [{$database}] invece di [reko_test]. "
                .'Probabilmente la configurazione è in cache: esegui php artisan optimize:clear.'
            );
        }

        return $app;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
