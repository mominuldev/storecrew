<?php
/**
 * AI provider layer verification.
 *
 * Run with:  wp eval-file wp-content/plugins/storecrew/tests/schema/verify-providers.php
 *
 * No network calls. A recording transport captures the exact payload each
 * provider would send, so request shaping is asserted rather than assumed —
 * that is where the expensive bugs live, because a mistranslated system prompt
 * or a wrongly-named role produces a worse answer, not an error.
 *
 * No declare(strict_types=1): wp eval-file runs this through eval().
 *
 * @package StoreCrew
 */

use StoreCrew\Ai\Capabilities;
use StoreCrew\Ai\ChatRequest;
use StoreCrew\Ai\ChatResponse;
use StoreCrew\Ai\EmbeddingRequest;
use StoreCrew\Ai\Exception\ProviderException;
use StoreCrew\Ai\Http\HttpClientInterface;
use StoreCrew\Ai\Message;
use StoreCrew\Ai\ModelPolicy;
use StoreCrew\Ai\Pricing;
use StoreCrew\Ai\Providers\AnthropicProvider;
use StoreCrew\Ai\Providers\GeminiProvider;
use StoreCrew\Ai\Providers\OpenAiProvider;
use StoreCrew\Ai\SpendGuard;
use StoreCrew\Ai\TokenUsage;
use StoreCrew\Api\Registry\ProviderRegistry;
use StoreCrew\Security\SecretStore;

$pass = 0;
$fail = 0;

$t = static function ( string $label, bool $ok, string $detail = '' ) use ( &$pass, &$fail ): void {
	if ( $ok ) {
		++$pass;
		echo "  PASS  {$label}\n";
	} else {
		++$fail;
		echo "  FAIL  {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";
	}
};

/** Records what a provider would send, and replays a canned response. */
$recorder = new class() implements HttpClientInterface {
	public string $url            = '';
	public array $headers         = array();
	public array $body            = array();
	public array $next_response   = array();

	public function post_json( string $url, array $headers, array $body, string $provider, int $timeout = 60 ): array {
		$this->url     = $url;
		$this->headers = $headers;
		$this->body    = $body;

		return array( 'status' => 200, 'body' => $this->next_response, 'latency_ms' => 42 );
	}

	public function get_json( string $url, array $headers, string $provider, int $timeout = 30 ): array {
		$this->url     = $url;
		$this->headers = $headers;

		return array( 'status' => 200, 'body' => $this->next_response, 'latency_ms' => 7 );
	}
};

$secrets = new SecretStore();

echo "\n== Secret store ==\n";
$secrets->put( 'probe.key', 'sk-test-abcdef123456' );
$t( 'stores and retrieves a secret', 'sk-test-abcdef123456' === $secrets->get( 'probe.key' ) );
$t( 'unknown secret returns null', null === $secrets->get( 'probe.missing' ) );
$t( 'hint masks the value', 'sk-…3456' === $secrets->hint( 'probe.key' ), (string) $secrets->hint( 'probe.key' ) );

$stored_raw = get_option( SecretStore::OPTION_SECRETS, array() );
$t(
	'PROBE: plaintext never hits the database',
	! str_contains( wp_json_encode( $stored_raw ), 'abcdef123456' )
);
$t( 'ciphertext is a versioned envelope', str_starts_with( (string) $stored_raw['probe.key'], 'scr1:' ) );

// A tampered ciphertext must fail closed, not yield attacker-controlled text.
$tampered                 = $stored_raw;
$parts                    = explode( ':', (string) $tampered['probe.key'] );
$parts[3]                 = base64_encode( 'garbage-payload-that-is-long-enough-to-parse' );
$tampered['probe.key2']   = implode( ':', $parts );
update_option( SecretStore::OPTION_SECRETS, $tampered, false );
$t( 'PROBE: tampered ciphertext decrypts to null, not garbage', null === $secrets->get( 'probe.key2' ) );
$secrets->forget( 'probe.key2' );

echo "\n== Key rotation must not destroy what it protects (FR-AI-03) ==\n";
$secrets->put( 'probe.second', 'second-secret-value' );
$before = $secrets->get( 'probe.key' );

$result = $secrets->rotate_data_key();
$t( 'data key rotation reports success', array() === $result['failed'], wp_json_encode( $result ) );
$t( 'PROBE: secrets survive data-key rotation', $before === $secrets->get( 'probe.key' ) );
$t( 'PROBE: every secret survives, not just the first', 'second-secret-value' === $secrets->get( 'probe.second' ) );
$t(
	'PROBE: ciphertext actually changed (rotation was real, not a no-op)',
	$stored_raw['probe.key'] !== get_option( SecretStore::OPTION_SECRETS )['probe.key']
);

$source = $secrets->master_key_source();
$t( 'reports where the master key came from', in_array( $source['source'], array( 'constant', 'salts', 'option' ), true ), $source['source'] );
$t( 'flags an insecure master key source', true === $source['secure'] || '' !== $source['advice'] );

echo "\n== Anthropic request shaping ==\n";
$secrets->put( 'provider.anthropic.key', 'sk-ant-probe' );
$anthropic = new AnthropicProvider( $secrets, $recorder );

$recorder->next_response = array(
	'model'       => 'claude-opus-5',
	'content'     => array( array( 'type' => 'text', 'text' => 'Hello.' ) ),
	'stop_reason' => 'end_turn',
	'usage'       => array(
		'input_tokens'                => 100,
		'output_tokens'               => 20,
		'cache_creation_input_tokens' => 5,
		'cache_read_input_tokens'     => 900,
	),
);

$response = $anthropic->chat(
	new ChatRequest(
		model: 'claude-opus-5',
		messages: array( Message::user( 'Do you sell trail shoes?' ) ),
		system: 'You are a shop assistant.',
		temperature: 0.7,
		cache_system: true,
		effort: 'high',
	)
);

$t( 'PROBE: temperature is NEVER sent (400s on current models)', ! array_key_exists( 'temperature', $recorder->body ) );
$t( 'PROBE: top_p is never sent', ! array_key_exists( 'top_p', $recorder->body ) );
$t( 'system is a top-level field, not a message', isset( $recorder->body['system'] ) );
$t( 'system is an array of blocks', isset( $recorder->body['system'][0]['type'] ) );
$t( 'cache breakpoint attached when requested', 'ephemeral' === ( $recorder->body['system'][0]['cache_control']['type'] ?? '' ) );
$t( 'no system role leaks into messages', 'user' === $recorder->body['messages'][0]['role'] );
$t( 'effort rides in output_config', 'high' === ( $recorder->body['output_config']['effort'] ?? '' ) );
$t( 'anthropic-version header is sent', '2023-06-01' === ( $recorder->headers['anthropic-version'] ?? '' ) );
$t( 'key sent as x-api-key, not Bearer', 'sk-ant-probe' === ( $recorder->headers['x-api-key'] ?? '' ) );

$t( 'usage: input mapped', 100 === $response->usage->input );
$t( 'usage: cache write mapped', 5 === $response->usage->cache_write );
$t( 'usage: cache read mapped', 900 === $response->usage->cache_read );
$t( 'usage: cache reads are separate from input', 100 !== $response->usage->total_input() && 1005 === $response->usage->total_input() );

// The failure mode that matters: HTTP 200, empty content, stop_reason refusal.
$recorder->next_response = array(
	'model'        => 'claude-opus-5',
	'content'      => array(),
	'stop_reason'  => 'refusal',
	'stop_details' => array( 'type' => 'refusal', 'category' => 'cyber' ),
	'usage'        => array( 'input_tokens' => 10, 'output_tokens' => 0 ),
);
$refused = $anthropic->chat( new ChatRequest( 'claude-opus-5', array( Message::user( 'x' ) ) ) );
$t( 'PROBE: refusal is detected, not read as an empty answer', $refused->is_refusal() );
$t( 'refusal carries stop_details for diagnosis', 'cyber' === ( $refused->raw_meta['stop_details']['category'] ?? '' ) );

$recorder->next_response['stop_reason'] = 'max_tokens';
$truncated = $anthropic->chat( new ChatRequest( 'claude-opus-5', array( Message::user( 'x' ) ) ) );
$t( 'truncation is distinguishable from completion', $truncated->is_truncated() && ! $truncated->is_refusal() );

echo "\n== OpenAI request shaping ==\n";
$secrets->put( 'provider.openai.key', 'sk-openai-probe' );
$openai = new OpenAiProvider( $secrets, $recorder );

$recorder->next_response = array(
	'model'   => 'gpt-4.1',
	'choices' => array( array( 'message' => array( 'content' => 'Hi.' ), 'finish_reason' => 'stop' ) ),
	'usage'   => array(
		'prompt_tokens'         => 1000,
		'completion_tokens'     => 50,
		'prompt_tokens_details' => array( 'cached_tokens' => 800 ),
	),
);

$oa = $openai->chat(
	new ChatRequest( 'gpt-4.1', array( Message::user( 'hello' ) ), system: 'Be helpful.', temperature: 0.3 )
);

$t( 'system becomes the first message', 'system' === $recorder->body['messages'][0]['role'] );
$t( 'user message follows the system message', 'user' === $recorder->body['messages'][1]['role'] );
$t( 'temperature IS sent here (supported)', 0.3 === ( $recorder->body['temperature'] ?? null ) );
$t( 'key sent as a Bearer token', 'Bearer sk-openai-probe' === ( $recorder->headers['Authorization'] ?? '' ) );
$t(
	'PROBE: cached tokens subtracted from input, not double-counted',
	200 === $oa->usage->input && 800 === $oa->usage->cache_read && 1000 === $oa->usage->total_input()
);

// Temperature must be omitted entirely when unset, not sent as null or 0.
$openai->chat( new ChatRequest( 'gpt-4.1', array( Message::user( 'hello' ) ) ) );
$t( 'PROBE: unset temperature is omitted, not sent as 0', ! array_key_exists( 'temperature', $recorder->body ) );

echo "\n== OpenAI embeddings ==\n";
$recorder->next_response = array(
	'model' => 'text-embedding-3-small',
	'data'  => array(
		array( 'index' => 1, 'embedding' => array( 0.4, 0.5, 0.6 ) ),
		array( 'index' => 0, 'embedding' => array( 0.1, 0.2, 0.3 ) ),
	),
	'usage' => array( 'prompt_tokens' => 12 ),
);
$emb = $openai->embed( new EmbeddingRequest( 'text-embedding-3-small', array( 'first', 'second' ) ) );
$t( 'returns one vector per input', 2 === $emb->count() );
$t(
	'PROBE: out-of-order results are re-sorted by index',
	array( 0.1, 0.2, 0.3 ) === $emb->vectors[0],
	wp_json_encode( $emb->vectors[0] )
);
$t( 'vectors are uniform', $emb->is_uniform() );
$t( 'dimensions reported', 3 === $emb->dimensions() );

echo "\n== Gemini request shaping ==\n";
$secrets->put( 'provider.gemini.key', 'gm-probe' );
$gemini = new GeminiProvider( $secrets, $recorder );

$recorder->next_response = array(
	'candidates'    => array(
		array(
			'content'      => array( 'parts' => array( array( 'text' => 'Bonjour.' ) ) ),
			'finishReason' => 'STOP',
		),
	),
	'usageMetadata' => array( 'promptTokenCount' => 30, 'candidatesTokenCount' => 8 ),
);

$gemini->chat(
	new ChatRequest(
		'gemini-2.5-pro',
		array( Message::user( 'hi' ), Message::assistant( 'hello' ), Message::user( 'again' ) ),
		system: 'Be brief.'
	)
);

$t( 'turns are "contents" with "parts"', isset( $recorder->body['contents'][0]['parts'][0]['text'] ) );
$t( 'PROBE: assistant role is spelled "model"', 'model' === $recorder->body['contents'][1]['role'] );
$t( 'user role stays "user"', 'user' === $recorder->body['contents'][0]['role'] );
$t( 'system prompt is systemInstruction', isset( $recorder->body['systemInstruction']['parts'][0]['text'] ) );
$t( 'key travels in the query string', str_contains( $recorder->url, 'key=gm-probe' ) );

$recorder->next_response['candidates'][0]['finishReason'] = 'SAFETY';
$blocked = $gemini->chat( new ChatRequest( 'gemini-2.5-pro', array( Message::user( 'x' ) ) ) );
$t( 'SAFETY maps to refusal', $blocked->is_refusal() );

echo "\n== Gemini embedding task types (FR-KB-06) ==\n";
$recorder->next_response = array( 'embeddings' => array( array( 'values' => array( 0.1, 0.2 ) ) ) );

$gemini->embed( new EmbeddingRequest( 'gemini-embedding-001', array( 'a product description' ) ) );
$t(
	'documents embed with RETRIEVAL_DOCUMENT',
	'RETRIEVAL_DOCUMENT' === ( $recorder->body['requests'][0]['taskType'] ?? '' )
);

$gemini->embed(
	new EmbeddingRequest( 'gemini-embedding-001', array( 'running shoes' ), EmbeddingRequest::TASK_QUERY )
);
$t(
	'PROBE: queries embed with RETRIEVAL_QUERY, not the document type',
	'RETRIEVAL_QUERY' === ( $recorder->body['requests'][0]['taskType'] ?? '' ),
	(string) ( $recorder->body['requests'][0]['taskType'] ?? 'missing' )
);

echo "\n== Capabilities are honest ==\n";
$t( 'Anthropic declares no embeddings', false === $anthropic->capabilities()->embeddings );
$t( 'Anthropic declares no sampling', false === $anthropic->capabilities()->sampling );
$t( 'Anthropic is not an EmbeddingProvider', ! ( $anthropic instanceof StoreCrew\Ai\EmbeddingProviderInterface ) );
$t( 'Gemini declares embedding task types', true === $gemini->capabilities()->embedding_task_types );
$t( 'OpenAI declares embeddings', true === $openai->capabilities()->embeddings );

echo "\n== Pricing ==\n";
$known = Pricing::estimate( 'anthropic', 'claude-opus-5', new TokenUsage( 1_000_000, 1_000_000 ) );
$t( 'known model reports a cost', true === $known['known'] );
$t( 'input+output priced correctly ($5 + $25)', 30_000_000 === $known['micros'], (string) $known['micros'] );

$cached = Pricing::estimate( 'anthropic', 'claude-opus-5', new TokenUsage( 0, 0, 0, 1_000_000 ) );
$t( 'cache reads bill at ~0.1x input', 500_000 === $cached['micros'], (string) $cached['micros'] );

$unknown = Pricing::estimate( 'openai', 'gpt-4.1', new TokenUsage( 1_000_000, 1_000_000 ) );
$t( 'PROBE: unknown model reports known=false', false === $unknown['known'] );
$t( 'PROBE: unknown model does not fabricate a cost', 0 === $unknown['micros'] );
$t( 'formats micros as currency', '$30.00' === Pricing::format( 30_000_000 ) );

echo "\n== Model policy ==\n";
$registry = new ProviderRegistry();
$registry->register( $anthropic );
$registry->register( $openai );
$policy = new ModelPolicy( $registry );

$chat = $policy->resolve( ModelPolicy::TASK_CHAT );
$t( 'infers a chat provider', null !== $chat );

$embedding = $policy->resolve( ModelPolicy::TASK_EMBEDDING );
$t( 'infers an embedding provider', null !== $embedding );
$t(
	'PROBE: embedding never resolves to a chat-only provider',
	'openai' === ( $embedding['provider'] ?? '' ),
	wp_json_encode( $embedding )
);

$anthropic_only = new ProviderRegistry();
$anthropic_only->register( $anthropic );
$lonely = new ModelPolicy( $anthropic_only );
$t(
	'PROBE: Anthropic-only install cannot resolve embeddings at all',
	null === $lonely->resolve( ModelPolicy::TASK_EMBEDDING )
);
$t( 'Anthropic-only install still resolves chat', null !== $lonely->resolve( ModelPolicy::TASK_CHAT ) );
$t( 'registry reports it cannot embed', ! $anthropic_only->can_embed() );

echo "\n== Spend guard ==\n";
$usage_repo = StoreCrew\Plugin::instance()->container()->get(
	StoreCrew\Database\Repositories\UsageRepository::class
);
$guard = new SpendGuard( $usage_repo );

$guard->set_cap( 0 );
$t( 'no cap means calls always allowed', $guard->allows_call() );

$guard->set_cap( 1, SpendGuard::BEHAVIOUR_STOP );
$usage_repo->record( 'tokens_in', 1, 0, 'probe', 'anthropic', 'claude-opus-5', 5_000_000 );
$t( 'PROBE: exceeding the cap blocks calls', ! $guard->allows_call() );

$guard->set_cap( 1, SpendGuard::BEHAVIOUR_WARN );
$t( 'warn behaviour allows the call through', $guard->allows_call() );
$t( 'status reports the breach', $guard->status()['spentMicros'] >= 5_000_000 );

echo "\n== Error classification ==\n";
$rate_limited = new ProviderException( 'slow down', 'anthropic', 429, true, 30 );
$t( '429 is retryable', $rate_limited->is_retryable() );
$t( 'retry-after is preserved', 30 === $rate_limited->retry_after() );
$t( '429 is not an auth failure', ! $rate_limited->is_auth_failure() );

$bad_key = new ProviderException( 'nope', 'openai', 401 );
$t( '401 is not retryable', ! $bad_key->is_retryable() );
$t( 'PROBE: 401 is identified as an auth failure', $bad_key->is_auth_failure() );

echo "\n== Cleanup ==\n";
foreach ( array( 'probe.key', 'probe.second', 'provider.anthropic.key', 'provider.openai.key', 'provider.gemini.key' ) as $name ) {
	$secrets->forget( $name );
}
delete_option( SpendGuard::OPTION_CAP_MICROS );
delete_option( SpendGuard::OPTION_ON_BREACH );
$GLOBALS['wpdb']->delete(
	StoreCrew\Database\Tables::name( StoreCrew\Database\Tables::USAGE_EVENTS ),
	array( 'agent_id' => 'probe' ),
	array( '%s' )
);
$usage_repo->rebuild_counters();
$t( 'probe secrets removed', array() === array_intersect( $secrets->names(), array( 'probe.key', 'provider.openai.key' ) ) );

echo "\n" . str_repeat( '-', 60 ) . "\n";
printf( "%d passed, %d failed\n", $pass, $fail );

if ( $fail > 0 ) {
	exit( 1 );
}
