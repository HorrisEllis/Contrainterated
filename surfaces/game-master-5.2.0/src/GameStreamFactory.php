<?php

namespace GameMaster;

use GameMaster\Boundary\AiTransport;
use GameMaster\Boundary\AnthropicTransport;
use GameMaster\Boundary\Driver;
use GameMaster\Boundary\FileStore;
use GameMaster\Boundary\MockAiTransport;
use GameMaster\Gates;
use Siso\Core\Gate;
use Siso\Core\Stream;
use Siso\Observer\StreamLog;

/**
 * GameStreamFactory — builds the stream and the boundary.
 *
 * Every gate has its own signature (event type), so one stream
 * holds all of them: which gate fires is decided by the event's
 * type alone. Registration order carries no meaning.
 *
 * Configuration is constructor-fixed. Only ZonePreGenerationGate
 * takes any (maxZonePreGenerate); the rest configures the
 * boundary (paths, AI transport).
 */
class GameStreamFactory
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'worldTemplatesPath' => __DIR__ . '/../data/worlds',
            'savesPath' => __DIR__ . '/../saves',
            'zonesPath' => __DIR__ . '/../data/zones',
            // AI transport
            'aiEndpoint' => function_exists('getAnthropicEndpoint') ? getAnthropicEndpoint() : 'https://api.anthropic.com/v1/messages',
            'aiKey' => function_exists('getAnthropicApiKey') ? getAnthropicApiKey() : (getenv('ANTHROPIC_API_KEY') ?: ''),
            'aiModel' => function_exists('getAnthropicModel') ? getAnthropicModel('claude-sonnet-4-20250514') : 'claude-sonnet-4-20250514',
            'aiMaxTokens' => 2048,
            'aiTimeout' => 30,
            'aiMaxRetries' => 2,
            'useMockAI' => false,
            // Zones
            'maxZonePreGenerate' => 3,
            'debug' => false,
        ], $config);
    }

    /**
     * Every gate, keyed by signature, built on demand.
     *
     * @return array<string, callable(): GameGate>
     */
    public function gateFactories(): array
    {
        $c = $this->config;
        return [
            // Templates
            'templates_requested' => fn() => new Gates\TemplateListGate(),
            'templates_loaded' => fn() => new Gates\TemplateCatalogGate(),
            // New game
            'game_create_requested' => fn() => new Gates\GameCreateGate(),
            'game_create_loaded' => fn() => new Gates\WorldTemplateGate(),
            'game_template_ready' => fn() => new Gates\GameInitGate(),
            'game_created' => fn() => new Gates\GameCreatedGate(),
            // Game state
            'game_load_requested' => fn() => new Gates\GameLoadGate(),
            'game_load_read' => fn() => new Gates\GameStateGate(),
            // Player turn
            'action_submitted' => fn() => new Gates\ActionParseGate(),
            'turn_state_loaded' => fn() => new Gates\TurnRouteGate(),
            'turn_template_loaded' => fn() => new Gates\ContextBuildGate(),
            'portland_turn_loaded' => fn() => new Gates\PortlandContextGate(),
            'turn_ai_replied' => fn() => new Gates\ResponseParseGate(),
            'turn_apply_loaded' => fn() => new Gates\TurnApplyRouteGate(),
            'generic_turn_parsed' => fn() => new Gates\StateUpdateGate(),
            'portland_turn_parsed' => fn() => new Gates\PortlandStateGate(),
            'turn_applied' => fn() => new Gates\GameBudgetGate(),
            'turn_accounted' => fn() => new Gates\GameSnapshotGate(),
            'turn_saved' => fn() => new Gates\GameResultGate(),
            'turn_failed' => fn() => new Gates\GameErrorGate(),
            // Zone generation
            'zone_generate_requested' => fn() => new Gates\ZoneGenerateRequestGate(),
            'zone_generate_loaded' => fn() => new Gates\ZoneGenerateCheckGate(),
            'zone_pregenerate_requested' => fn() => new Gates\ZonePregenerateRequestGate(),
            'zone_pregenerate_loaded' => fn() => new Gates\ZonePreGenerationGate((int) $c['maxZonePreGenerate']),
            'zone_target' => fn() => new Gates\ZoneContextGate(),
            'zone_ai_replied' => fn() => new Gates\ZoneParseGate(),
            'zones_settled' => fn() => new Gates\ZoneStoreGate(),
            'zone_generate_stored' => fn() => new Gates\ZoneGenerateResultGate(),
            'zone_pregenerate_stored' => fn() => new Gates\ZonePregenerateResultGate(),
            // Zone lookup / maintenance
            'zone_get_requested' => fn() => new Gates\ZoneGetGate(),
            'zone_get_read' => fn() => new Gates\ZoneGetResultGate(),
            'zone_list_requested' => fn() => new Gates\ZoneListGate(),
            'zone_list_read' => fn() => new Gates\ZoneListResultGate(),
            'zone_delete_requested' => fn() => new Gates\ZoneDeleteGate(),
            'zone_delete_loaded' => fn() => new Gates\ZoneDeleteApplyGate(),
            'zone_file_deleted' => fn() => new Gates\ZoneIndexRemoveGate(),
            'zone_deleted' => fn() => new Gates\ZoneDeletedGate(),
        ];
    }

    /** One gate's definition (its GameGate class), by signature, from the project's source. */
    public function definition(string $signature): GameGate
    {
        $factories = $this->gateFactories();
        if (!isset($factories[$signature])) {
            throw new \InvalidArgumentException("No gate registered for '{$signature}'");
        }
        $definition = $factories[$signature]();
        if ($definition->signature !== $signature) {
            throw new \LogicException("Gate for '{$signature}' declares '{$definition->signature}'");
        }
        return $definition;
    }

    /** One core gate, by signature, built from the project's source. */
    public function gate(string $signature): Gate
    {
        return $this->definition($signature)->gate();
    }

    /**
     * A stream with every gate registered. With a log, the log's
     * observer is attached (observer-side only: attaching it changes
     * nothing the stream does).
     */
    public function createStream(?StreamLog $log = null): Stream
    {
        $stream = new Stream();
        foreach (array_keys($this->gateFactories()) as $signature) {
            $stream->register($this->gate($signature));
        }
        if ($log !== null) {
            $stream->attach($log->observer('request'));
        }
        return $stream;
    }

    public function createStore(): FileStore
    {
        return new FileStore(
            $this->config['savesPath'],
            $this->config['worldTemplatesPath'],
            $this->config['zonesPath']
        );
    }

    public function createTransport(): AiTransport
    {
        if ($this->config['useMockAI']) {
            return new MockAiTransport();
        }
        return new AnthropicTransport(
            $this->config['aiEndpoint'],
            (string) $this->config['aiKey'],
            $this->config['aiModel'],
            (int) $this->config['aiMaxTokens'],
            (int) $this->config['aiTimeout'],
            (int) $this->config['aiMaxRetries']
        );
    }

    /**
     * A driver over a fresh stream. Pass a transport to override
     * the configured one (tests use a scripted MockAiTransport).
     */
    public function createDriver(?AiTransport $transport = null, ?StreamLog $log = null): Driver
    {
        return new Driver(
            $this->createStream($log),
            $this->createStore(),
            $transport ?? $this->createTransport()
        );
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function isAIConfigured(): bool
    {
        return !empty($this->config['aiKey']);
    }
}
