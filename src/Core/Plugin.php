<?php

namespace CoderEmbassy\AiSeoAutomation\Core;

use CoderEmbassy\AiSeoAutomation\Admin\AdminMenu;
use CoderEmbassy\AiSeoAutomation\Admin\MetaBox;
use CoderEmbassy\AiSeoAutomation\Api\ApiClientInterface;
use CoderEmbassy\AiSeoAutomation\Api\AnthropicClient;
use CoderEmbassy\AiSeoAutomation\Api\GeminiClient;
use CoderEmbassy\AiSeoAutomation\Api\GroqClient;
use CoderEmbassy\AiSeoAutomation\Api\OpenAiClient;
use CoderEmbassy\AiSeoAutomation\Engine\GenerationEngine;
use CoderEmbassy\AiSeoAutomation\Jobs\JobManager;
use CoderEmbassy\AiSeoAutomation\Jobs\Queue;
use CoderEmbassy\AiSeoAutomation\Jobs\RateLimiter;
use CoderEmbassy\AiSeoAutomation\Jobs\Worker;
use CoderEmbassy\AiSeoAutomation\Repository\AuditRepository;
use CoderEmbassy\AiSeoAutomation\Repository\JobRepository;
use CoderEmbassy\AiSeoAutomation\Repository\ProductRepository;
use CoderEmbassy\AiSeoAutomation\Repository\RulesRepository;
use CoderEmbassy\AiSeoAutomation\Rest\ApplyController;
use CoderEmbassy\AiSeoAutomation\Rest\ExportController;
use CoderEmbassy\AiSeoAutomation\Rest\LicenseController;
use CoderEmbassy\AiSeoAutomation\Rest\RollbackController;
use CoderEmbassy\AiSeoAutomation\Rest\RulesController;
use CoderEmbassy\AiSeoAutomation\Rest\SeoController;
use CoderEmbassy\AiSeoAutomation\Rest\SettingsController;
use CoderEmbassy\AiSeoAutomation\Services\LicenseManager;
use CoderEmbassy\AiSeoAutomation\Services\MetaWriter;
use CoderEmbassy\AiSeoAutomation\Services\RollbackManager;
use CoderEmbassy\AiSeoAutomation\Services\SchemaGenerator;
use CoderEmbassy\AiSeoAutomation\Services\SeoOutputService;
use CoderEmbassy\AiSeoAutomation\Services\UsageMeter;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap — wires the Container and kicks off all subsystems.
 */
class Plugin {

    private static Container $container;

    /**
     * Boot the plugin.
     */
    public static function boot(): void {
        if ( ! self::has_woocommerce() ) {
            add_action( 'admin_notices', [ self::class, 'woocommerce_missing_notice' ] );
            return;
        }

        self::$container = new Container();
        self::registerBindings( self::$container );

        $tier = defined( 'CE_AI_SEO_TIER' ) ? CE_AI_SEO_TIER : 'free';

        // Run pending migrations on admin load when DB version is behind (e.g. after plugin update).
        add_action( 'admin_init', function () {
            $current = (int) get_option( 'ce_ai_seo_db_version', 0 );
            if ( $current < 5 ) {
                ( new \CoderEmbassy\AiSeoAutomation\Database\MigrationManager() )->run_pending();
            }
        } );

        // Declare WooCommerce compatibility (HPOS, etc.)
        add_action( 'before_woocommerce_init', function () {
            if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CE_AI_SEO_PLUGIN_FILE, true );
            }
        } );

        // REST API controllers
        add_action( 'rest_api_init', function () use ( $tier ) {
            // Free + Pro + Scale
            self::$container->make( SeoController::class )->register_routes();
            self::$container->make( ApplyController::class )->register_routes();
            self::$container->make( RollbackController::class )->register_routes();
            self::$container->make( SettingsController::class )->register_routes();

            // Pro + Scale features
            if ( in_array( $tier, [ 'pro', 'scale' ], true ) ) {
                self::$container->make( RulesController::class )->register_routes();
                self::$container->make( ExportController::class )->register_routes();
                self::$container->make( LicenseController::class )->register_routes();
            }
        } );

        // Admin menu, metabox + asset enqueuing
        self::$container->make( AdminMenu::class )->register_hooks();
        self::$container->make( MetaBox::class )->register_hooks();
        self::$container->make( Assets::class )->register_hooks();

        // Frontend SEO output (meta tags + alt text)
        self::$container->make( SeoOutputService::class )->register_hooks();

        // Pro + Scale features
        if ( in_array( $tier, [ 'pro', 'scale' ], true ) ) {
            // Queue cron
            self::$container->make( Queue::class )->registerHooks();

            // Autopilot (auto-queue SEO on product save)
            self::$container->make( \CoderEmbassy\AiSeoAutomation\Automation\Autopilot::class )->register_hooks();

            if ( defined( 'WP_CLI' ) && WP_CLI ) {
                \WP_CLI::add_command( 'aiwoo', \CoderEmbassy\AiSeoAutomation\Cli\AiWooCommand::class );
            }
        }

        // Scale-only features
        if ( $tier === 'scale' ) {
            // MultisiteManager::register_hooks() goes here when built.
        }
    }

    /**
     * Return the resolved container (e.g. for use in Queue callbacks).
     */
    public static function container(): Container {
        return self::$container;
    }

    /**
     * Register all service bindings on the container.
     */
    private static function registerBindings( Container $c ): void {

        $tier = defined( 'CE_AI_SEO_TIER' ) ? CE_AI_SEO_TIER : 'free';

        // ── Repositories ──────────────────────────────────────────────────────
        $c->singleton( ProductRepository::class, fn() => new ProductRepository() );
        $c->singleton( AuditRepository::class,   fn() => new AuditRepository() );
        $c->singleton( JobRepository::class,     fn() => new JobRepository() );
        $c->singleton( RulesRepository::class,   fn() => new RulesRepository() );

        // ── Services ──────────────────────────────────────────────────────────
        $c->singleton( UsageMeter::class, fn() => new UsageMeter() );
        $c->singleton( MetaWriter::class,  fn() => new MetaWriter() );

        if ( in_array( $tier, [ 'pro', 'scale' ], true ) ) {
            $c->singleton( LicenseManager::class, fn() => new LicenseManager() );
        }

        $c->singleton( RollbackManager::class, fn( Container $c ) => new RollbackManager(
            $c->make( AuditRepository::class )
        ) );

        // ── AI API client (resolved from saved settings) ───────────────────
        $c->singleton( ApiClientInterface::class, function () {
            $provider = (string) get_option( 'ce_ai_seo_provider', 'openai' );
            $key      = (string) get_option( "ce_ai_seo_{$provider}_key", '' );

            if ( $provider === 'anthropic' ) {
                $model = (string) get_option( 'ce_ai_seo_anthropic_model', 'claude-haiku-4-5-20251001' );
                return new AnthropicClient( $key, $model );
            }

            if ( $provider === 'groq' ) {
                $model = (string) get_option( 'ce_ai_seo_groq_model', 'llama-3.3-70b-versatile' );
                return new GroqClient( $key, $model );
            }

            if ( $provider === 'gemini' ) {
                $model = (string) get_option( 'ce_ai_seo_gemini_model', 'gemini-2.0-flash' );
                return new GeminiClient( $key, $model );
            }

            $model = (string) get_option( 'ce_ai_seo_openai_model', 'gpt-4o-mini' );
            return new OpenAiClient( $key, $model );
        } );

        // ── Engine ────────────────────────────────────────────────────────────
        $c->singleton( GenerationEngine::class, fn( Container $c ) => new GenerationEngine(
            $c->make( ApiClientInterface::class ),
            $c->make( ProductRepository::class ),
            $c->make( UsageMeter::class ),
            $c->make( RulesRepository::class )
        ) );

        // ── Jobs ──────────────────────────────────────────────────────────────
        $c->singleton( RateLimiter::class, function () {
            $provider = (string) get_option( 'ce_ai_seo_provider', 'openai' );
            return new RateLimiter( $provider );
        } );

        $c->singleton( Worker::class, fn( Container $c ) => new Worker(
            $c->make( JobRepository::class ),
            $c->make( GenerationEngine::class ),
            $c->make( RateLimiter::class ),
            $c->make( MetaWriter::class )
        ) );

        $c->singleton( JobManager::class, fn( Container $c ) => new JobManager(
            $c->make( JobRepository::class )
        ) );

        if ( in_array( $tier, [ 'pro', 'scale' ], true ) ) {
            $c->singleton( Queue::class, fn( Container $c ) => new Queue( $c ) );
        }

        // ── Frontend SEO output ───────────────────────────────────────────────
        $c->singleton( SchemaGenerator::class, fn() => new SchemaGenerator() );

        $c->singleton( SeoOutputService::class, fn( Container $c ) => new SeoOutputService(
            $c->make( SchemaGenerator::class )
        ) );

        // ── Admin ─────────────────────────────────────────────────────────────
        $c->singleton( AdminMenu::class, fn() => new AdminMenu() );
        $c->singleton( MetaBox::class,   fn() => new MetaBox() );
        $c->singleton( Assets::class,    fn() => new Assets() );

        // ── REST Controllers ──────────────────────────────────────────────────
        $c->singleton( SeoController::class, fn( Container $c ) => new SeoController(
            $c->make( JobManager::class ),
            $c->make( JobRepository::class ),
            $c->make( GenerationEngine::class ),
            $c->make( Worker::class )
        ) );

        $c->singleton( ApplyController::class, fn( Container $c ) => new ApplyController(
            $c->make( JobRepository::class ),
            $c->make( ProductRepository::class ),
            $c->make( AuditRepository::class )
        ) );

        $c->singleton( RollbackController::class, fn( Container $c ) => new RollbackController(
            $c->make( RollbackManager::class )
        ) );

        $c->singleton( SettingsController::class, fn( Container $c ) => new SettingsController(
            $c->make( UsageMeter::class ),
            in_array( $tier, [ 'pro', 'scale' ], true ) ? $c->make( LicenseManager::class ) : null
        ) );

        if ( in_array( $tier, [ 'pro', 'scale' ], true ) ) {
            $c->singleton( LicenseController::class, fn( Container $c ) => new LicenseController(
                $c->make( LicenseManager::class )
            ) );
        }

        if ( in_array( $tier, [ 'pro', 'scale' ], true ) ) {
            $c->singleton( RulesController::class, fn( Container $c ) => new RulesController(
                $c->make( RulesRepository::class )
            ) );

            $c->singleton( ExportController::class, fn( Container $c ) => new ExportController(
                $c->make( AuditRepository::class )
            ) );

            // ── Automation ─────────────────────────────────────────────────────
            $c->singleton( \CoderEmbassy\AiSeoAutomation\Automation\Autopilot::class, fn( Container $c ) => new \CoderEmbassy\AiSeoAutomation\Automation\Autopilot(
                $c->make( JobManager::class ),
                $c->make( RulesRepository::class ),
                $c->make( MetaWriter::class )
            ) );
        }
    }

    /**
     * Check if WooCommerce is active.
     */
    private static function has_woocommerce(): bool {
        return class_exists( 'WooCommerce' );
    }

    /**
     * Admin notice when WooCommerce is not active.
     */
    public static function woocommerce_missing_notice(): void {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e( 'AI WooCommerce Product SEO Automation requires WooCommerce to be installed and active.', 'CoderEmbassy-AI-SEO-Automation' ); ?></p>
        </div>
        <?php
    }
}
