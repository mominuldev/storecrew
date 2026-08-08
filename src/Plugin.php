<?php
/**
 * Plugin kernel.
 *
 * @package StoreCrew
 */

declare( strict_types=1 );

namespace StoreCrew;

defined( 'ABSPATH' ) || exit; // Guard placed here (not after the use block) so it sits in the file header where Plugin Check looks for it.

use StoreCrew\Ai\Http\CurlSseClient;
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
use StoreCrew\Api\Attribution;
use StoreCrew\Api\Secrets;
use StoreCrew\Api\Rest\Controllers\AgentController;
use StoreCrew\Api\Rest\Controllers\BootstrapController;
use StoreCrew\Api\Rest\Controllers\ChatController;
use StoreCrew\Api\Rest\Controllers\ConversationController;
use StoreCrew\Api\Rest\Controllers\HealthController;
use StoreCrew\Api\Rest\Controllers\IndexController;
use StoreCrew\Api\Rest\Controllers\KnowledgeController;
use StoreCrew\Api\Rest\Controllers\ProviderController;
use StoreCrew\Api\Rest\Controllers\SettingsController;
use StoreCrew\Api\Registry\AdminRouteRegistry;
use StoreCrew\Agent\AgentRunner;
use StoreCrew\Agent\CoreAgents;
use StoreCrew\Agent\Orchestrator;
use StoreCrew\Agent\Tool\ToolExecutor;
use StoreCrew\Agent\Tools\HandoffTool;
use StoreCrew\Agent\Tools\IdentityVerifyTool;
use StoreCrew\Agent\Tools\OrderLookupTool;
use StoreCrew\Agent\Tools\OrderNoteTool;
use StoreCrew\Agent\Tools\PolicyLookupTool;
use StoreCrew\Agent\Tools\ProductLookupTool;
use StoreCrew\Agent\Tools\ProductSearchTool;
use StoreCrew\Api\Registry\AgentRegistry;
use StoreCrew\Api\Registry\ControllerRegistry;
use StoreCrew\Api\Registry\ExtractorRegistry;
use StoreCrew\Api\Registry\FeatureRegistry;
use StoreCrew\Api\Registry\ProviderRegistry;
use StoreCrew\Api\Registry\ToolRegistry;
use StoreCrew\Knowledge\Chunker;
use StoreCrew\Knowledge\Extractor\PostExtractor;
use StoreCrew\Knowledge\Extractor\ProductExtractor;
use StoreCrew\Knowledge\Indexer;
use StoreCrew\Knowledge\Jobs\EmbedJob;
use StoreCrew\Knowledge\Jobs\IndexJob;
use StoreCrew\Knowledge\Jobs\ReindexJob;
use StoreCrew\Knowledge\Retriever;
use StoreCrew\Knowledge\SourceSelection;
use StoreCrew\Security\SecretStore;
use StoreCrew\Chat\ChatService;
use StoreCrew\Chat\ConsoleService;
use StoreCrew\Chat\EscalationNotifier;
use StoreCrew\Chat\OrderAttribution;
use StoreCrew\Chat\Widget;
use StoreCrew\Core\Admin\AdminPage;
use StoreCrew\Core\Container\Container;
use StoreCrew\Core\Onboarding;
use StoreCrew\Core\SetupProgress;
use StoreCrew\Core\Privacy\PersonalData;
use StoreCrew\Core\Queue\MaintenanceJob;
use StoreCrew\Core\Queue\Scheduler;
use StoreCrew\Database\MigrationInterface;
use StoreCrew\Database\Migrations\Migration001InitialSchema;
use StoreCrew\Database\Migrations\Migration002RunCostKnown;
use StoreCrew\Database\Migrations\Migration003DropUpgradeFlag;
use StoreCrew\Database\Migrations\Migration004DropVersionOption;
use StoreCrew\Database\Migrations\Migration005Attributions;
use StoreCrew\Database\Migrator;
use StoreCrew\Database\Repositories\AgentConfigRepository;
use StoreCrew\Database\Repositories\AgentRunRepository;
use StoreCrew\Database\Repositories\AttributionRepository;
use StoreCrew\Database\Repositories\AuditLogRepository;
use StoreCrew\Database\Repositories\ConversationRepository;
use StoreCrew\Database\Repositories\IndexRunRepository;
use StoreCrew\Database\Repositories\KnowledgeChunkRepository;
use StoreCrew\Database\Repositories\KnowledgeSourceRepository;
use StoreCrew\Database\Repositories\MessageRepository;
use StoreCrew\Database\Repositories\ToolCallRepository;
use StoreCrew\Database\Repositories\UsageRepository;
use StoreCrew\Licensing\FeatureGate;
use StoreCrew\Licensing\Quota;

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
		$this->register_core_tools();
		$this->register_core_agents();
		$this->register_core_controllers();

		$this->api = $this->container->get( ExtensionApi::class );

		// Registration window. Add-ons hook storecrew_api_ready at priority 10;
		// adding these from inside plugins_loaded:5 is safe because WordPress
		// re-reads the callback list as it walks the remaining priorities.
		add_action( 'plugins_loaded', array( $this->api, 'open' ), 10 );
		add_action( 'plugins_loaded', array( $this->api, 'freeze' ), 20 );

		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->register_reindex_hooks();
		$this->register_jobs();

		// Registered unconditionally. Both hooks it attaches (admin_menu,
		// admin_enqueue_scripts) are admin-only already, so an is_admin()
		// guard here would gate a gate — and would make the page unreachable
		// under WP-CLI, where is_admin() is false, hiding menu bugs from the
		// only harness that could catch them.
		( new AdminPage() )->register();

		// The storefront widget. Registered unconditionally for the same reason
		// as the admin page: the hooks it attaches already decide for themselves
		// whether this request is one they belong on, and a guard here would
		// only hide them from WP-CLI — where the tests run.
		( new Widget() )->register();

		// Personal-data exporter and eraser (04 § 11). The *filters* register
		// here; the object — and therefore its repositories — resolves only
		// when a privacy screen actually asks, because constructing a
		// repository requires a $wpdb the DB-free harness deliberately does
		// not have. (Eager construction here is the mistake this codebase has
		// now made five times; see CLAUDE.md.)
		$privacy = function (): PersonalData {
			return $this->container->get( PersonalData::class );
		};

		add_filter( 'wp_privacy_personal_data_exporters', static fn ( $e ) => $privacy()->register_exporter( $e ) );
		add_filter( 'wp_privacy_personal_data_erasers', static fn ( $e ) => $privacy()->register_eraser( $e ) );

		// Attribution (FR-ANALYTICS-03). Lazily resolved for the same reason as
		// the privacy object: this constructs three repositories, and the
		// overwhelming majority of requests that reach here are not checkouts.
		$attribution = function (): OrderAttribution {
			return $this->container->get( OrderAttribution::class );
		};

		foreach ( OrderAttribution::HOOKS as $checkout_hook ) {
			add_action( $checkout_hook, static fn ( $order ) => $attribution()->from_order( $order ), 10, 1 );
		}

		// Routes register on rest_api_init, which fires long after the
		// registries freeze, so the contributed set is always final by then.
		add_action(
			'rest_api_init',
			function (): void {
				$this->container->get( ControllerRegistry::class )->register_routes();
			}
		);

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
			AgentRegistry::class,
			static fn (): AgentRegistry => new AgentRegistry()
		);

		$this->container->set(
			ToolRegistry::class,
			static fn (): ToolRegistry => new ToolRegistry()
		);

		$this->container->set(
			ToolExecutor::class,
			static fn ( Container $c ): ToolExecutor => new ToolExecutor(
				$c->get( ToolRegistry::class ),
				$c->get( ToolCallRepository::class ),
				$c->get( AgentConfigRepository::class ),
				$c->get( AuditLogRepository::class ),
				// For executing an approved write later: the agent's allow-list
				// and audience, the run that names the agent, and the
				// conversation whose identity state is re-read at that point.
				$c->get( AgentRegistry::class ),
				$c->get( AgentRunRepository::class ),
				$c->get( ConversationRepository::class )
			)
		);

		$this->container->set(
			AgentRunner::class,
			static fn ( Container $c ): AgentRunner => new AgentRunner(
				$c->get( ProviderRegistry::class ),
				$c->get( ModelPolicy::class ),
				$c->get( ToolRegistry::class ),
				$c->get( ToolExecutor::class ),
				$c->get( AgentRunRepository::class ),
				$c->get( AgentConfigRepository::class ),
				$c->get( UsageRepository::class ),
				$c->get( SpendGuard::class )
			)
		);

		$this->container->set(
			Orchestrator::class,
			static fn ( Container $c ): Orchestrator => new Orchestrator(
				$c->get( AgentRegistry::class ),
				$c->get( AgentRunner::class ),
				$c->get( ProviderRegistry::class ),
				$c->get( ModelPolicy::class ),
				$c->get( FeatureGate::class ),
				$c->get( AgentConfigRepository::class ),
				$c->get( SpendGuard::class )
			)
		);

		$this->container->set(
			ChatService::class,
			static fn ( Container $c ): ChatService => new ChatService(
				$c->get( ConversationRepository::class ),
				$c->get( MessageRepository::class ),
				$c->get( Orchestrator::class ),
				$c->get( UsageRepository::class )
			)
		);

		$this->container->set(
			ConsoleService::class,
			static fn ( Container $c ): ConsoleService => new ConsoleService(
				$c->get( ConversationRepository::class ),
				$c->get( MessageRepository::class ),
				$c->get( Orchestrator::class )
			)
		);

		$this->container->set(
			Quota::class,
			static fn (): Quota => new Quota()
		);

		$this->container->set(
			ControllerRegistry::class,
			static fn (): ControllerRegistry => new ControllerRegistry()
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
			SourceSelection::class,
			static fn ( Container $c ): SourceSelection => new SourceSelection(
				$c->get( ExtractorRegistry::class )
			)
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
				$c->get( SpendGuard::class ),
				$c->get( SourceSelection::class )
			)
		);

		$this->container->set(
			Onboarding::class,
			static fn ( Container $c ): Onboarding => new Onboarding(
				$c->get( ProviderRegistry::class ),
				$c->get( ModelPolicy::class ),
				$c->get( SourceSelection::class ),
				$c->get( Indexer::class ),
				// Resolved when the state is computed, not when it is wired:
				// the orchestrator drags in the whole agent stack, and the
				// bootstrap payload is the only thing that asks for this.
				static fn (): array => $c->get( Orchestrator::class )->available_agents()
			)
		);

		$this->container->set(
			SetupProgress::class,
			static fn ( Container $c ): SetupProgress => new SetupProgress(
				$c->get( UsageRepository::class )
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
				$c->get( Scheduler::class ),
				$c->get( SourceSelection::class )
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
			EscalationNotifier::class,
			static fn ( Container $c ): EscalationNotifier => new EscalationNotifier(
				$c->get( ConversationRepository::class )
			)
		);

		$this->container->set(
			PersonalData::class,
			static fn ( Container $c ): PersonalData => new PersonalData(
				$c->get( ConversationRepository::class ),
				$c->get( MessageRepository::class ),
				$c->get( AttributionRepository::class )
			)
		);

		$this->container->set(
			MaintenanceJob::class,
			static fn ( Container $c ): MaintenanceJob => new MaintenanceJob(
				$c->get( IndexRunRepository::class ),
				$c->get( AgentRunRepository::class ),
				$c->get( ConversationRepository::class ),
				$c->get( AuditLogRepository::class ),
				$c->get( Scheduler::class ),
				$c->get( MessageRepository::class ),
				$c->get( ToolCallRepository::class ),
				$c->get( UsageRepository::class ),
				$c->get( AttributionRepository::class )
			)
		);

		$this->container->set(
			SecretStore::class,
			static fn (): SecretStore => new SecretStore()
		);

		// The published half of the same object (Api\Secrets, four methods), so
		// an add-on storing a third-party credential never has to invent its own
		// encryption — and never has to name a class the boundary rule forbids.
		// Resolved through the container rather than constructed again: it
		// memoises, and two stores over one option would be two caches of the
		// same data key.
		$this->container->set(
			Secrets::class,
			fn (): Secrets => $this->container->get( SecretStore::class )
		);

		$this->container->set(
			OrderAttribution::class,
			static fn ( Container $c ): OrderAttribution => new OrderAttribution(
				$c->get( ConversationRepository::class ),
				$c->get( AttributionRepository::class ),
				$c->get( AgentRunRepository::class )
			)
		);

		// The published half of the same object (Api\Attribution), on the same
		// reasoning as Secrets above: premium reports on these links, the free
		// plugin is the only thing that can record them, and the methodology
		// has to come from the recorder rather than be restated by the reader.
		$this->container->set(
			Attribution::class,
			fn (): Attribution => $this->container->get( OrderAttribution::class )
		);

		$this->container->set(
			HttpClient::class,
			static fn (): HttpClient => new HttpClient()
		);

		$this->container->set(
			CurlSseClient::class,
			static fn (): CurlSseClient => new CurlSseClient()
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
			AttributionRepository::class,
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
				$migrations = array(
					new Migration001InitialSchema(),
					new Migration002RunCostKnown(),
					new Migration003DropUpgradeFlag(),
					new Migration004DropVersionOption(),
					new Migration005Attributions(),
				);

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
				$c->get( ExtractorRegistry::class ),
				$c->get( ControllerRegistry::class ),
				$c->get( AgentRegistry::class ),
				$c->get( ToolRegistry::class )
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
		$sse      = $this->container->get( CurlSseClient::class );

		$registry->register( new AnthropicProvider( $secrets, $http ) );
		$registry->register( new OpenAiProvider( $secrets, $http ) );
		$registry->register( new GeminiProvider( $secrets, $http, $sse ) );
		$registry->register( new OpenRouterProvider( $secrets, $http ) );
		$registry->register( new DeepSeekProvider( $secrets, $http ) );
	}

	/**
	 * Register the tools this plugin ships.
	 */
	private function register_core_tools(): void {
		$c        = $this->container;
		$registry = $c->get( ToolRegistry::class );

		// Factories. Nothing here — including retrieval and its repositories —
		// is constructed until a turn actually reaches for the tool.
		$registry->register(
			ProductSearchTool::ID,
			static fn (): ProductSearchTool => new ProductSearchTool( $c->get( Retriever::class ) )
		);

		$registry->register(
			PolicyLookupTool::ID,
			static fn (): PolicyLookupTool => new PolicyLookupTool( $c->get( Retriever::class ) )
		);

		$registry->register( ProductLookupTool::ID, static fn (): ProductLookupTool => new ProductLookupTool() );

		$registry->register(
			IdentityVerifyTool::ID,
			static fn (): IdentityVerifyTool => new IdentityVerifyTool(
				$c->get( ConversationRepository::class ),
				$c->get( AuditLogRepository::class )
			)
		);

		$registry->register( OrderLookupTool::ID, static fn (): OrderLookupTool => new OrderLookupTool() );
		$registry->register( OrderNoteTool::ID, static fn (): OrderNoteTool => new OrderNoteTool() );

		// The availability callable defers to the orchestrator lazily, so
		// constructing the tool costs nothing and the target list is always
		// the same one routing itself would use.
		$registry->register(
			HandoffTool::ID,
			static fn (): HandoffTool => new HandoffTool(
				static fn (): array => $c->get( Orchestrator::class )->available_agents()
			)
		);
	}

	/**
	 * Register the agents this plugin ships.
	 */
	private function register_core_agents(): void {
		$registry = $this->container->get( AgentRegistry::class );

		$registry->register( CoreAgents::sales() );
		$registry->register( CoreAgents::support() );
	}

	/**
	 * Register the REST controllers this plugin ships.
	 */
	private function register_core_controllers(): void {
		$c        = $this->container;
		$registry = $c->get( ControllerRegistry::class );

		// Factories, not instances — see ControllerRegistry. Nothing here is
		// constructed until rest_api_init.
		$gate = static fn () => $c->get( FeatureGate::class );

		$registry->register(
			'bootstrap',
			static fn (): BootstrapController => new BootstrapController(
				$gate(),
				$c->get( Onboarding::class ),
				$c->get( SetupProgress::class )
			)
		);

		$registry->register(
			'agents',
			static fn (): AgentController => new AgentController(
				$gate(),
				$c->get( AgentRegistry::class ),
				$c->get( AgentConfigRepository::class ),
				$c->get( AuditLogRepository::class )
			)
		);

		$registry->register(
			'health',
			static fn (): HealthController => new HealthController(
				$gate(),
				$c->get( Scheduler::class ),
				$c->get( Indexer::class ),
				$c->get( IndexRunRepository::class ),
				$c->get( SpendGuard::class ),
				$c->get( SecretStore::class ),
				$c->get( UsageRepository::class ),
				$c->get( Quota::class )
			)
		);

		$registry->register(
			'providers',
			static fn (): ProviderController => new ProviderController(
				$gate(),
				$c->get( ProviderRegistry::class ),
				$c->get( SecretStore::class ),
				$c->get( AuditLogRepository::class )
			)
		);

		$registry->register(
			'settings',
			static fn (): SettingsController => new SettingsController(
				$gate(),
				$c->get( ModelPolicy::class ),
				$c->get( SpendGuard::class ),
				$c->get( ProviderRegistry::class ),
				$c->get( AuditLogRepository::class )
			)
		);

		$registry->register(
			'index',
			static fn (): IndexController => new IndexController(
				$gate(),
				$c->get( Indexer::class ),
				$c->get( IndexJob::class ),
				$c->get( EmbedJob::class ),
				$c->get( IndexRunRepository::class ),
				$c->get( ExtractorRegistry::class ),
				$c->get( Scheduler::class ),
				$c->get( SourceSelection::class )
			)
		);

		$registry->register(
			'knowledge',
			static fn (): KnowledgeController => new KnowledgeController(
				$gate(),
				$c->get( Retriever::class ),
				$c->get( KnowledgeSourceRepository::class )
			)
		);

		$registry->register(
			'chat',
			static fn (): ChatController => new ChatController(
				$gate(),
				$c->get( ChatService::class ),
				$c->get( Orchestrator::class ),
				$c->get( ModelPolicy::class ),
				$c->get( UsageRepository::class ),
				$c->get( Quota::class )
			)
		);

		$registry->register(
			'conversations',
			static fn (): ConversationController => new ConversationController(
				$gate(),
				$c->get( ConversationRepository::class ),
				$c->get( MessageRepository::class ),
				$c->get( AgentRunRepository::class ),
				$c->get( ToolCallRepository::class ),
				$c->get( ToolExecutor::class )
			)
		);
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

		// The escalation doorbell (FR-SUPPORT-07's push half). Lazy like every
		// other deferred consumer, and gated on the transition flag so one
		// escalation is one email however many turns fail after it.
		add_action(
			'storecrew_conversation_escalated',
			static function ( $conversation_id, $turn, $transitioned = false ) use ( $container ): void {
				if ( true === $transitioned && $turn instanceof \StoreCrew\Agent\AgentTurn ) {
					$container->get( EscalationNotifier::class )->notify( (int) $conversation_id, $turn );
				}
			},
			10,
			3
		);

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
		// Kept intentionally: WordPress.org auto-loads language packs, but this
		// also loads translations a site ships in the plugin's own /languages
		// (e.g. before a .org language pack exists, or for a private build).
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- see above; intentional support for local translations.
		load_plugin_textdomain(
			'storecrew',
			false,
			dirname( STORECREW_BASENAME ) . '/languages'
		);
	}
}
