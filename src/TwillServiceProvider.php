<?php

namespace A17\Twill;

use A17\Twill\Commands\CreateSuperAdmin;
use A17\Twill\Commands\GenerateBlocks;
use A17\Twill\Commands\Install;
use A17\Twill\Commands\ModuleMake;
use A17\Twill\Commands\RefreshLQIP;
use A17\Twill\Http\ViewComposers\ActiveNavigation;
use A17\Twill\Http\ViewComposers\CurrentUser;
use A17\Twill\Http\ViewComposers\FilesUploaderConfig;
use A17\Twill\Http\ViewComposers\MediasUploaderConfig;
use A17\Twill\Models\Block;
use A17\Twill\Models\File;
use A17\Twill\Models\Media;
use A17\Twill\Models\User;
use A17\Twill\Services\FileLibrary\FileService;
use A17\Twill\Services\MediaLibrary\ImageService;
use Barryvdh\Debugbar\Facade as Debugbar;
use Barryvdh\Debugbar\ServiceProvider as DebugbarServiceProvider;
use Cartalyst\Tags\TagsServiceProvider;
use Dimsav\Translatable\TranslatableServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\ServiceProvider;
use Lsrur\Inspector\Facade\Inspector;
use Lsrur\Inspector\InspectorServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use View;

class TwillServiceProvider extends ServiceProvider
{
    protected $providers = [
        RouteServiceProvider::class,
        AuthServiceProvider::class,
        ValidationServiceProvider::class,
        TranslatableServiceProvider::class,
        TagsServiceProvider::class,
        ActivitylogServiceProvider::class,
    ];

    public function boot()
    {
        $this->requireHelpers();

        $this->publishConfigs();
        $this->publishMigrations();

        $this->registerCommands();

        $this->registerAndPublishViews();

        $this->extendBlade();
        $this->addViewComposers();
    }

    public function register()
    {
        $this->mergeConfigs();

        $this->registerProviders();
        $this->registerAliases();

        Relation::morphMap([
            'users' => User::class,
            'media' => Media::class,
            'files' => File::class,
            'blocks' => Block::class,
        ]);

        config(['twill.version' => trim(file_get_contents(__DIR__ . '/../VERSION'))]);
    }

    private function registerProviders()
    {
        foreach ($this->providers as $provider) {
            $this->app->register($provider);
        }

        if ($this->app->environment('development', 'local', 'staging')) {
            if (config('twill.debug.use_inspector', false)) {
                $this->app->register(InspectorServiceProvider::class);
            } else {
                $this->app->register(DebugbarServiceProvider::class);
            }
        }

        if (config('twill.enabled.media-library')) {
            $this->app->singleton('imageService', function () {
                return $this->app->make(config('twill.media_library.image_service'));
            });
        }

        if (config('twill.enabled.file-library')) {
            $this->app->singleton('fileService', function () {
                return $this->app->make(config('twill.file_library.file_service'));
            });
        }
    }

    private function registerAliases()
    {
        $loader = AliasLoader::getInstance();

        if (config('twill.debug.use_inspector', false)) {
            $loader->alias('Inspector', Inspector::class);
        } else {
            $loader->alias('Debugbar', Debugbar::class);
        }

        if (config('twill.enabled.media-library')) {
            $loader->alias('ImageService', ImageService::class);
        }

        if (config('twill.enabled.file-library')) {
            $loader->alias('FileService', FileService::class);
        }

    }

    private function publishConfigs()
    {
        if (config('twill.enabled.users-management')) {
            config(['auth.providers.twill_users' => [
                'driver' => 'eloquent',
                'model' => User::class,
            ]]);

            config(['auth.guards.twill_users' => [
                'driver' => 'session',
                'provider' => 'twill_users',
            ]]);

            config(['auth.passwords.twill_users' => [
                'provider' => 'twill_users',
                'table' => config('twill.password_resets_table', 'twill_password_resets'),
                'expire' => 60,
            ]]);

            config(['mail.markdown.paths' => array_merge(
                [__DIR__ . '/../views/emails'],
                config('mail.markdown.paths')
            )]);
        }

        config(['activitylog.enabled' => config('twill.enabled.dashboard') ? true : config('twill.enabled.activitylog')]);
        config(['activitylog.subject_returns_soft_deleted_models' => true]);

        config(['analytics.service_account_credentials_json' => config('twill.dashboard.analytics.service_account_credentials_json', storage_path('app/analytics/service-account-credentials.json'))]);

        $this->publishes([__DIR__ . '/../config/twill-publish.php' => config_path('twill.php')], 'config');
        $this->publishes([__DIR__ . '/../config/twill-navigation.php' => config_path('twill-navigation.php')], 'config');
        $this->publishes([__DIR__ . '/../config/translatable.php' => config_path('translatable.php')], 'config');
    }

    private function mergeConfigs()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/twill.php', 'twill');
        $this->mergeConfigFrom(__DIR__ . '/../config/disks.php', 'filesystems.disks');
        $this->mergeConfigFrom(__DIR__ . '/../config/frontend.php', 'twill.frontend');
        $this->mergeConfigFrom(__DIR__ . '/../config/debug.php', 'twill.debug');
        $this->mergeConfigFrom(__DIR__ . '/../config/seo.php', 'twill.seo');
        $this->mergeConfigFrom(__DIR__ . '/../config/blocks.php', 'twill.block_editor');
        $this->mergeConfigFrom(__DIR__ . '/../config/enabled.php', 'twill.enabled');
        $this->mergeConfigFrom(__DIR__ . '/../config/imgix.php', 'twill.imgix');
        $this->mergeConfigFrom(__DIR__ . '/../config/media-library.php', 'twill.media_library');
        $this->mergeConfigFrom(__DIR__ . '/../config/file-library.php', 'twill.file_library');
        $this->mergeConfigFrom(__DIR__ . '/../config/cloudfront.php', 'services');
        $this->mergeConfigFrom(__DIR__ . '/../config/dashboard.php', 'twill.dashboard');
    }

    private function publishMigrations()
    {
        $migrations = ['CreateTagsTables', 'CreateBlocksTable', 'CreateRelatedTable'];

        $optionalMigrations = [
            'CreateTwillUsersTables' => 'users-management',
            'CreateFilesTables' => 'file-library',
            'CreateMediasTables' => 'media-library',
            'CreateFeaturesTable' => 'buckets',
            'CreateSettingsTable' => 'settings',
        ];

        if ($this->app->runningInConsole()) {
            foreach ($migrations as $migration) {
                $this->publishMigration($migration);
            }

            foreach ($optionalMigrations as $migration => $feature) {
                if (config('twill.enabled.' . $feature)) {
                    $this->publishMigration($migration);
                }
            }
        }
    }

    private function publishMigration($migration)
    {
        if (!class_exists($migration)) {
            $timestamp = date('Y_m_d_His', time());
            $this->publishes([
                __DIR__ . '/../migrations/' . snake_case($migration) . '.php' => database_path('migrations/' . $timestamp . '_' . snake_case($migration) . '.php'),
            ], 'migrations');
        }
    }

    private function registerAndPublishViews()
    {
        $viewPath = __DIR__ . '/../views';

        $this->loadViewsFrom($viewPath, 'twill');
        $this->publishes([$viewPath => resource_path('views/vendor/twill')], 'views');
    }

    private function registerCommands()
    {
        $this->commands([
            Install::class,
            ModuleMake::class,
            CreateSuperAdmin::class,
            RefreshLQIP::class,
            GenerateBlocks::class,
        ]);
    }

    private function requireHelpers()
    {
        require_once __DIR__ . '/Helpers/routes_helpers.php';
        require_once __DIR__ . '/Helpers/i18n_helpers.php';
        require_once __DIR__ . '/Helpers/media_library_helpers.php';
        require_once __DIR__ . '/Helpers/frontend_helpers.php';
        require_once __DIR__ . '/Helpers/migrations_helpers.php';
        require_once __DIR__ . '/Helpers/helpers.php';
    }

    private function includeView($view, $expression)
    {
        list($name) = str_getcsv($expression, ',', '\'');

        $partialNamespace = view()->exists('admin.' . $view . $name) ? 'admin.' : 'twill::';

        $view = $partialNamespace . $view . $name;

        $expression = explode(',', $expression);
        array_shift($expression);
        $expression = "(" . implode(',', $expression) . ")";
        if ($expression === "()") {
            $expression = '([])';
        }
        return "<?php echo \$__env->make('{$view}', array_except(get_defined_vars(), ['__data', '__path']))->with{$expression}->render(); ?>";
    }

    private function extendBlade()
    {
        $blade = $this->app['view']->getEngineResolver()->resolve('blade')->getCompiler();

        $blade->directive('dd', function ($param) {
            return "<?php dd({$param}); ?>";
        });

        $blade->directive('dumpData', function ($data) {
            return sprintf("<?php (new Symfony\Component\VarDumper\VarDumper)->dump(%s); exit; ?>",
                null != $data ? $data : "get_defined_vars()");
        });

        $blade->directive('formField', function ($expression) use ($blade) {
            return $this->includeView('partials.form._', $expression);
        });

        $blade->directive('partialView', function ($expression) use ($blade) {

            $expressionAsArray = str_getcsv($expression, ',', '\'');

            list($moduleName, $viewName) = $expressionAsArray;
            $partialNamespace = 'twill::partials';

            $viewModule = "'admin.'.$moduleName.'.{$viewName}'";
            $viewApplication = "'admin.partials.{$viewName}'";
            $viewModuleTwill = "'twill::'.$moduleName.'.{$viewName}'";
            $view = $partialNamespace . "." . $viewName;

            if (!isset($moduleName) || is_null($moduleName)) {
                $viewModule = $viewApplication;
            }

            $expression = explode(',', $expression);
            $expression = array_slice($expression, 2);
            $expression = "(" . implode(',', $expression) . ")";
            if ($expression === "()") {
                $expression = '([])';
            }

            return "<?php
            if( view()->exists($viewModule)) {
                echo \$__env->make($viewModule, array_except(get_defined_vars(), ['__data', '__path']))->with{$expression}->render();
            } elseif( view()->exists($viewApplication)) {
                echo \$__env->make($viewApplication, array_except(get_defined_vars(), ['__data', '__path']))->with{$expression}->render();
            } elseif( view()->exists($viewModuleTwill)) {
                echo \$__env->make($viewModuleTwill, array_except(get_defined_vars(), ['__data', '__path']))->with{$expression}->render();
            } elseif( view()->exists('$view')) {
                echo \$__env->make('$view', array_except(get_defined_vars(), ['__data', '__path']))->with{$expression}->render();
            }
            ?>";
        });

        $blade->directive('pushonce', function ($expression) {
            list($pushName, $pushSub) = explode(':', trim(substr($expression, 1, -1)));
            $key = '__pushonce_' . $pushName . '_' . str_replace('-', '_', $pushSub);
            return "<?php if(! isset(\$__env->{$key})): \$__env->{$key} = 1; \$__env->startPush('{$pushName}'); ?>";
        });

        $blade->directive('endpushonce', function () {
            return '<?php $__env->stopPush(); endif; ?>';
        });
    }

    private function addViewComposers()
    {
        if (config('twill.enabled.users-management')) {
            View::composer(['admin.*', 'twill::*'], CurrentUser::class);
        }

        if (config('twill.enabled.media-library')) {
            View::composer('twill::layouts.main', MediasUploaderConfig::class);
        }

        if (config('twill.enabled.file-library')) {
            View::composer('twill::layouts.main', FilesUploaderConfig::class);
        }

        View::composer('twill::partials.navigation.*', ActiveNavigation::class);

        View::composer(['admin.*', 'templates.*', 'twill::*'], function ($view) {
            $with = array_merge([
                'renderForBlocks' => false,
                'renderForModal' => false,
            ], $view->getData());

            return $view->with($with);
        });

    }

    private function registerAndPublishTranslations()
    {
        $translationPath = __DIR__ . '/../lang';

        $this->loadTranslationsFrom($translationPath, 'twill');
        $this->publishes([$translationPath => resource_path('lang/vendor/twill')], 'translations');
    }
}
