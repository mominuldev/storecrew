<?php
/**
 * Plugin kernel.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew;

use StoreCrew\Ai\Http\HttpClient;
use StoreCrew\Ai\ModelPolicy;
use StoreCrew\Ai\Providers\AnthropicProvider;
use StoreCrew\Ai\Providers\DeepSeekProvider;
use StoreCrew\Ai\Providers\GeminiProvider;
use StoreCrew\Ai\Providers\OpenAiProvider;
use StoreCrew\Ai\Providers\OpenRouterProvider;
use StoreCrew\Ai\SpendGuard;
use StoreCrew\Api\ExtensionApi;
use StoreCrew\Api\Feature;
use StoreCrew\Api\Registry\AdminRouteRegistry;
use StoreCrew\Api\Registry\ExtractorRegistry;
use StoreCrew\Api\Registry\FeatureRegistry;
use StoreCrew\Api\Registry\ProviderRegistry;
use StoreCrew\Knowledge\Chunker;
use StoreCrew\Knowledge\Extractor\PostExtractor;
use StoreCrew\Knowledge\Extractor\ProductExtractor;
use StoreCrew\Knowledge\Indexer;
use StoreCrew\Knowledge\Jobs\EmbedJob;
use StoreCrew\Knowledge\Jobs\IndexJob;
use StoreCrew\Knowledge\Jobs\ReindexJob;
use StoreCrew\Knowledge\Retriever;
use StoreCrew\Security\SecretStore;
use StoreCrew\Core\Container\Container;
use StoreCrew\Core\Queue\MaintenanceJob;
use StoreCrew\Core\Queue\Scheduler;
use StoreCrew\Database\MigrationInterface;
use StoreCrew\Database\Migrations\Migration001InitialSchema;
use StoreCrew\Database\Migrator;
use StoreCrew\Database\Repositories\AgentConfigRepository;
use StoreCrew\Database\Repositories\AgentRunRepository;
use StoreCrew\Database\Repositories\AuditLogRepository;
use StoreCrew\Database\Repositories\ConversationRepository;
use StoreCrew\Database\Repositories\IndexRunRepository;
use StoreCrew\Database\Repositories\KnowledgeChunkRepository;
use StoreCrew\Database\Repositories\KnowledgeSourceRepository;
use StoreCrew\Database\Repositories\MessageRepository;
use StoreCrew\Database\Repositories\ToolCallRepository;
use StoreCrew\Database\Repositories\UsageRepository;
use StoreCrew\Licensing\FeatureGate;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the container, opens the extension API, and closes it again.
 *
 * The kernel does very little on purpose. Its whole job is to establish a
 * deterministic registration window — open at plugins_loaded 10, frozen at 20 —
 * so that everything downstream reads a stable set of contributions.
 *
 * @see docs/15-free-premium-split.md § 3.1
 */
final class Plugin {

	private static ?self $instance = null;

	private Container $container;

	private ExtensionApi $api;

	private bool $booted = false;

	private function __construct() {
		$this->container = new Container();
	}

	/**
	 * The kernel instance.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * The service container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * The extension API.
	 */
	public function api(): ExtensionApi {
		return $this->api;
	}

	/**
	 * Boot the plugin. Called on plugins_loaded at priority 5.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->register_services();
		$this->register_core_features();
		$this->register_core_providers();
		$this->register_core_extractors();

		$this->api = $this->container->get( ExtensionApi::class );

		// Registration window. Add-ons hook storecrew_api_ready at priority 10;
		// adding these from inside plugins_loaded:5 is safe because WordPress
		// re-reads the callback list as it walks the remaining priorities.
		add_action( 'plugins_loaded', array( $this->api, 'open' ), 10 );
		add_action( 'plugins_loaded', array( $this->api, 'freeze' ), 20 );

		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->register_reindex_hooks();
		$this->register_jobs();

		// Schema reconciliation runs here rather than on activation: a fatal
		// mid-migration during activation leaves a site with no way to retry,
		// and updating by file upload never fires the activation hook at all.
		add_action( 'admin_init', array( $this, 'maybe_migrate' ) );

		// admin_init never fires under WP-CLI, so a site administered purely by
		// `wp` would never get its tables.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			add_action( 'init', array( $this, 'maybe_migrate' ), 5 );
		}
	}

	/**
	 * Apply pending migrations, if any.
	 */
	public function maybe_migrate(): void {
		$migrator = $this->container->get( Migrator::class );

		if ( ! $migrator->needs_migration() ) {
			return;
		}

		$result = $migrator->run();

		if ( null === $result['failed'] ) {
			return;
		}

		$failed = $result['failed'];
		$error  = $result['error'];

		add_action(
			'admin_notices',
			static function () use ( $failed, $error ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				echo '<div class="notice notice-error"><p>';
				printf(
					/* translators: 1: migration version, 2: error message */
					esc_html__(
						'StoreCrew AI could not apply database migration %1$d: %2$s',
						'storecrew'
					),
					(int) $failed,
					esc_html( $error )
				);
				echo '</p></div>';
			}
		);
	}

	/**
	 * Register core service definitions.
	 */
	private function register_services(): void {
		$this->container->set(
			FeatureRegistry::class,
			static fn (): FeatureRegistry => new FeatureRegistry()
		);

		$this->container->set(
			AdminRouteRegistry::class,
			static fn (): AdminRouteRegistry => new AdminRouteRegistry()
		);

		$this->container->set(
			ProviderRegistry::class,
			static fn (): ProviderRegistry => new ProviderRegistry()
		);

		$this->container->set(
			ExtractorRegistry::class,
			static fn (): ExtractorRegistry => new ExtractorRegistry()
		);

		$this->container->set(
			Chunker::class,
			static fn (): Chunker => new Chunker()
		);

		$this->container->set(
			Indexer::class,
			static fn ( Container $c ): Indexer => new Indexer(
				$c->get( ExtractorRegistry::class ),
				$c->get( ProviderRegistry::class ),
				$c->get( ModelPolicy::class ),
				$c->get( Chunker::class ),
				$c->get( KnowledgeSourceRepository::class ),
				$c->get( KnowledgeChunkRepository::class ),
				$c->get( UsageRepository::class ),
				$c->get( SpendGuard::class )
			)
		);

		$this->container->set(
			Retriever::class,
			static fn ( Container $c ): Retriever => new Retriever(
				$c->get( ProviderRegistry::class ),
				$c->get( ModelPolicy::class ),
				$c->get( KnowledgeChunkRepository::class ),
				$c->get( KnowledgeSourceRepository::class )
			)
		);

		$this->container->set(
			Scheduler::class,
			static fn (): Scheduler => new Scheduler()
		);

		$this->container->set(
			IndexJob::class,
			static fn ( Container $c ): IndexJob => new IndexJob(
				$c->get( ExtractorRegistry::class ),
				$c->get( Indexer::class ),
				$c->get( IndexRunRepository::class ),
				$c->get( Scheduler::class )
			)
		);

		$this->container->set(
			EmbedJob::class,
			static fn ( Container $c ): EmbedJob => new EmbedJob(
				$c->get( Indexer::class ),
				$c->get( Scheduler::class )
			)
		);

		$this->container->set(
			ReindexJob::class,
			static fn ( Container $c ): ReindexJob => new ReindexJob(
				$c->get( Indexer::class ),
				$c->get( Scheduler::class )
			)
		);

		$this->container->set(
			MaintenanceJob::class,
			static fn ( Container $c ): MaintenanceJob => new MaintenanceJob(
				$c->get( IndexRunRepository::class ),
				$c->get( AgentRunRepository::class ),
				$c->get( ConversationRepository::class ),
				$c->get( AuditLogRepository::class ),
				$c->get( Scheduler::class )
			)
		);

		$this->container->set(
			SecretStore::class,
			static fn (): SecretStore => new SecretStore()
		);

		$this->container->set(
			HttpClient::class,
			static fn (): HttpClient => new HttpClient()
		);

		$this->container->set(
			ModelPolicy::class,
			static fn ( Container $c ): ModelPolicy => new ModelPolicy(
				$c->get( ProviderRegistry::class )
			)
		);

		$this->container->set(
			SpendGuard::class,
			static fn ( Container $c ): SpendGuard => new SpendGuard(
				$c->get( UsageRepository::class )
			)
		);

		$this->container->set(
			FeatureGate::class,
			static fn ( Container $c ): FeatureGate => new FeatureGate(
				$c->get( FeatureRegistry::class ),
				$c->get( AdminRouteRegistry::class )
			)
		);

		// Repositories are the only things that touch the database. They take
		// $wpdb from the global by default so a test can inject a double.
		$repositories = array(
			ConversationRepository::class,
			MessageRepository::class,
			AgentRunRepository::class,
			ToolCallRepository::class,
			KnowledgeSourceRepository::class,
			KnowledgeChunkRepository::class,
			UsageRepository::class,
			IndexRunRepository::class,
			AuditLogRepository::class,
			AgentConfigRepository::class,
		);

		foreach ( $repositories as $repository ) {
			$this->container->set(
				$repository,
				static fn (): object => new $repository()
			);
		}

		$this->container->set(
			Migrator::class,
			static function (): Migrator {
				$migrations = array( new Migration001InitialSchema() );

				/**
				 * Contribute database migrations.
				 *
				 * Add-ons own their own version series and must not reuse core
				 * numbers. Resolved lazily on admin_init, well after the
				 * registration window, so add-on callbacks are always present.
				 *
				 * @param list<MigrationInterface> $migrations Registered migrations.
				 */
				$migrations = apply_filters( 'storecrew_register_migrations', $migrations );

				if ( ! is_array( $migrations ) ) {
					$migrations = array();
				}

				// A malformed contribution must not take the schema down with it.
				$migrations = array_values(
					array_filter(
						$migrations,
						static fn ( $m ): bool => $m instanceof MigrationInterface
					)
				);

				return new Migrator( $migrations );
			}
		);

		$this->container->set(
			ExtensionApi::class,
			static fn ( Container $c ): ExtensionApi => new ExtensionApi(
				$c,
				$c->get( FeatureRegistry::class ),
				$c->get( AdminRouteRegistry::class ),
				$c->get( ProviderRegistry::class ),
				$c->get( ExtractorRegistry::class )
			)
		);
	}

	/**
	 * Register the features this plugin owns.
	 *
	 * Only free-tier features are declared here. Premium and Agency features are
	 * registered by the plugins that implement them — the free plugin does not
	 * enumerate capabilities it cannot deliver. Upgrade messaging is handled by
	 * a dedicated upsell screen rather than by seeding the registry with slugs
	 * that resolve to nothing.
	 *
	 * @see docs/15-free-premium-split.md § 7 (storecrew.noProReferenceInFree)
	 */
	private function register_core_features(): void {
		$features = $this->container->get( FeatureRegistry::class );

		$features->register(
			new Feature(
				slug: 'agent.sales',
				label: __( 'Sales agent', 'storecrew' ),
				tier: Feature::TIER_FREE,
				description: __( 'Finds products, answers pre-purchase questions, and suggests alternatives.', 'storecrew' ),
			)
		);

		$features->register(
			new Feature(
				slug: 'agent.support',
				label: __( 'Support agent', 'storecrew' ),
				tier: Feature::TIER_FREE,
				description: __( 'Resolves order, delivery, and returns enquiries.', 'storecrew' ),
			)
		);

		$features->register(
			new Feature(
				slug: 'knowledge.base',
				label: __( 'Knowledge base', 'storecrew' ),
				tier: Feature::TIER_FREE,
				description: __( 'Indexes products, pages, and policies for grounded answers.', 'storecrew' ),
			)
		);

		$features->register(
			new Feature(
				slug: 'chat.widget',
				label: __( 'Storefront chat', 'storecrew' ),
				tier: Feature::TIER_FREE,
				description: __( 'The customer-facing chat widget.', 'storecrew' ),
			)
		);
	}

	/**
	 * Register the AI providers this plugin ships.
	 *
	 * All five are registered regardless of whether a key is configured — the
	 * settings screen needs to offer them before they can be configured, and
	 * `is_configured()` is what gates actual use.
	 *
	 * @see docs/01-prd.md FR-AI-01
	 */
	private function register_core_providers(): void {
		$registry = $this->container->get( ProviderRegistry::class );
		$secrets  = $this->container->get( SecretStore::class );
		$http     = $this->container->get( HttpClient::class );

		$registry->register( new AnthropicProvider( $secrets, $http ) );
		$registry->register( new OpenAiProvider( $secrets, $http ) );
		$registry->register( new GeminiProvider( $secrets, $http ) );
		$registry->register( new OpenRouterProvider( $secrets, $http ) );
		$registry->register( new DeepSeekProvider( $secrets, $http ) );
	}

	/**
	 * Register background job handlers.
	 *
	 * Handlers are registered on every request, not just when a job is queued —
	 * Action Scheduler runs them in a later request than the one that scheduled
	 * them, so a hook registered conditionally would never fire.
	 *
	 * @see docs/01-prd.md FR-CORE-06
	 */
	private function register_jobs(): void {
		$container = $this->container;

		// Resolve lazily. Registering a handler must not construct the job — and
		// therefore its repositories — on every request; a storefront page load
		// that will never run a job should not pay to build one. Action
		// Scheduler executes handlers in a later request anyway, so the object
		// is built when the work actually runs.
		$lazy = static function ( string $class, string $method = 'run' ) use ( $container ): callable {
			return static function ( ...$args ) use ( $container, $class, $method ) {
				return $container->get( $class )->{$method}( ...$args );
			};
		};

		add_action( IndexJob::HOOK, $lazy( IndexJob::class ), 10, 1 );
		add_action( EmbedJob::HOOK, $lazy( EmbedJob::class ) );
		add_action( ReindexJob::HOOK, $lazy( ReindexJob::class ), 10, 2 );
		add_action( MaintenanceJob::HOOK, $lazy( MaintenanceJob::class ) );

		// The kernel's save hooks emit this; the reindex job consumes it.
		add_action( 'storecrew_queue_reindex', $lazy( ReindexJob::class, 'queue' ), 10, 2 );

		// Scheduling the recurring sweep belongs on an admin request, not on
		// every storefront hit.
		add_action( 'admin_init', $lazy( MaintenanceJob::class, 'ensure_scheduled' ) );
	}

	/**
	 * Register the knowledge-base extractors this plugin ships.
	 */
	private function register_core_extractors(): void {
		$registry = $this->container->get( ExtractorRegistry::class );

		$registry->register( new ProductExtractor() );
		$registry->register( new PostExtractor() );
	}

	/**
	 * Keep the index current as content changes.
	 *
	 * FR-KB-07 requires incremental re-indexing — a single product edit must
	 * never force a full rebuild. The work is queued rather than done inline:
	 * embedding on a save_post request would make the merchant's editor wait on
	 * a provider round trip, and a bulk edit would make it wait on hundreds.
	 */
	private function register_reindex_hooks(): void {
		$queue = static function ( int $object_id, string $source_type ): void {
			if ( wp_is_post_revision( $object_id ) || wp_is_post_autosave( $object_id ) ) {
				return;
			}

			/**
			 * Fires when an object needs re-indexing.
			 *
			 * @param string $source_type Extractor source type.
			 * @param int    $object_id   Object id.
			 */
			do_action( 'storecrew_queue_reindex', $source_type, $object_id );
		};

		add_action(
			'woocommerce_update_product',
			static fn ( $id ) => $queue( (int) $id, ProductExtractor::SOURCE_TYPE )
		);

		add_action(
			'woocommerce_new_product',
			static fn ( $id ) => $queue( (int) $id, ProductExtractor::SOURCE_TYPE )
		);

		add_action(
			'save_post',
			static function ( $id, $post ) use ( $queue ): void {
				if ( $post instanceof \WP_Post && in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
					$queue( (int) $id, PostExtractor::SOURCE_TYPE );
				}
			},
			10,
			2
		);

		// Deletions must drop the source immediately. A retrievable chunk for a
		// deleted product is worse than a stale one — the agent would recommend
		// something that no longer exists.
		add_action(
			'before_delete_post',
			function ( $id, $post ): void {
				if ( ! $post instanceof \WP_Post ) {
					return;
				}

				$type = 'product' === $post->post_type
					? ProductExtractor::SOURCE_TYPE
					: PostExtractor::SOURCE_TYPE;

				$this->container->get( Indexer::class )->forget( $type, (int) $id );
			},
			10,
			2
		);
	}

	/**
	 * Load translations.
	 *
	 * On `init` rather than `plugins_loaded`: WordPress 6.7 warns when a text
	 * domain is loaded before the point translations are actually available.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'storecrew',
			false,
			dirname( STORECREW_BASENAME ) . '/languages'
		);
	}
}
