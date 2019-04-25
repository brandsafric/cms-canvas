<?php

namespace CmsCanvas\Providers;

use App, Event, DateTime, View, Request, Cache, Validator;
use Illuminate\Support\ServiceProvider;
use CmsCanvas\Theme\ThemePublisher;
use CmsCanvas\Commands\ThemePublishCommand;
use CmsCanvas\Theme\Theme;
use CmsCanvas\Admin\Admin;
use CmsCanvas\Content\Content;
use CmsCanvas\Models\Setting;

class CmsCanvasServiceProvider extends ServiceProvider {

    const VERSION = '2.1.7';

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        \DB::enableQueryLog();

        $this->setupConfig();
        $this->setupViews();
        $this->setupPublishing();
        $this->setupValidations();
    }

    /**
     * Setup the config.
     *
     * @return void
     */
    public function setupConfig()
    {
        $source = realpath(__DIR__.'/../../config/config.php');
        $this->publishes([$source => config_path('cmscanvas/config.php')], 'config');
        $this->mergeConfigFrom($source, 'cmscanvas.config');

        // Convert relative paths in config to URLs 
        config([
            'cmscanvas.config.theme_assets_url' => asset(config('cmscanvas.config.theme_assets_url')),
            'cmscanvas.config.avatars_url' => asset(config('cmscanvas.config.avatars_url')),
            'cmscanvas.config.thumbnails_url' => asset(config('cmscanvas.config.thumbnails_url')),
        ]);

        $source = realpath(__DIR__.'/../../config/admin.php');
        $this->publishes([$source => config_path('cmscanvas/admin.php')], 'config');
        $this->mergeConfigFrom($source, 'cmscanvas.admin');

        $source = realpath(__DIR__.'/../../config/assets.php');
        $this->mergeConfigFrom($source, 'cmscanvas.assets');

        $this->mergeConfigFromDatabase();

        // Add version to CMS Canvas config
        $config = $this->app['config']->get('cmscanvas.config', []);
        $this->app['config']->set('cmscanvas.config', array_merge($config, ['version' => self::VERSION]));
    }

    /**
     * Merges settings defined in the database to the configs
     *
     * @return void
     */
    public function mergeConfigFromDatabase()
    {
        if ( ! $this->app->runningInConsole()) {
            // Set config settings stored in the database
            $settings = Cache::rememberForever('settings', function() {
                foreach(Setting::all() as $setting) {
                    $settings[$setting->setting] = $setting->value;
                }
                return $settings;
            });

            $config = $this->app['config']->get('cmscanvas.config', []);
            $this->app['config']->set('cmscanvas.config', array_merge($settings, $config));
        }
    }

    /**
     * Setup the config.
     *
     * @return void
     */
    public function setupViews()
    {
        $source = realpath(__DIR__.'/../../resources/views/');
        $this->loadViewsFrom($source, 'cmscanvas');
    }

    /**
     * Defines file groups to be published
     *
     * @return void
     */
    public function setupPublishing()
    {
        $this->publishes(
            [__DIR__.'/../../resources/themes/default' => base_path('/resources/themes/default')],
            'themes'
        );

        $this->publishes(
            [__DIR__.'/../../database/migrations/' => database_path('migrations')],
            'migrations'
        );

        $this->publishes(
            [__DIR__.'/../../../public/' => public_path('diyphpdeveloper/cmscanvas'),],
            'public'
        );
    }

    /**
     * Adds custom form validations
     *
     * @return void
     */
    public function setupValidations()
    {
        Validator::extend('nullable', function($attribute, $value, $parameters, $validator) {
            return $value == null;
        });
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerTheme();
        $this->registerDisplayError();
        $this->registerAdmin();
        $this->registerContent();
    }

    /**
     * Register theme provider.
     *
     * @return void
     */
    protected function registerTheme()
    {
        $this->app->alias('theme', 'CmsCanvas\Theme\Theme');
        $this->app->singleton('theme', function($app) {
            return new Theme;
        });
    }

    /**
     * Register admin provider.
     *
     * @return void
     */
    protected function registerAdmin()
    {
        $this->app->bind('admin', function($app) {
            return new Admin;
        });
    }

    /**
     * Register content provider.
     *
     * @return void
     */
    protected function registerContent()
    {
        $this->app->bind('content', function() {
            return new Content;
        });
    }

    /**
     * Register app error view.
     *
     * @return void
     */
    protected function registerDisplayError()
    {
        $this->app->singleton(
            'Illuminate\Contracts\Debug\ExceptionHandler',
            'CmsCanvas\Exceptions\Handler'
        );
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [
            'theme',
            'admin',
            'content',
        ];
    }

}
