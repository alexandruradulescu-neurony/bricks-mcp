<?php
/**
 * Design pattern handler for MCP Router.
 *
 * Manages the design pattern library: list, get, create, update, delete, export, import.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Handlers;

use BricksMCP\MCP\Services\BricksService;
use BricksMCP\MCP\Services\DesignPatternService;
use BricksMCP\MCP\Services\ImageInputResolver;
use BricksMCP\MCP\Services\ImageSideloadService;
use BricksMCP\MCP\Services\PatternCapture;
use BricksMCP\MCP\Services\PatternValidator;
use BricksMCP\MCP\Services\ProposalService;
use BricksMCP\MCP\Services\ReferenceJsonTranslator;
use BricksMCP\MCP\Services\VisionPromptBuilder;
use BricksMCP\MCP\Services\VisionProvider;
use BricksMCP\MCP\Services\VisionResponseMapper;
use BricksMCP\MCP\ToolRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles design_pattern tool actions.
 */
final class DesignPatternHandler {

	/**
	 * ProposalService instance. v3.32 orchestrator dep.
	 *
	 * @var object|null
	 */
	private $proposal_service = null;

	/**
	 * Bricks check callback.
	 *
	 * @var callable
	 */
	private $require_bricks;

	/**
	 * Bricks service instance.
	 *
	 * Loose-typed to accept test anon stubs that expose get_global_class_service()
	 * and get_global_variable_service() without extending the final class.
	 *
	 * @var object|null
	 */
	private $bricks_service = null;

	/**
	 * Build-structure handler. v3.32 orchestrator dep.
	 *
	 * @var object|null
	 */
	private $build_structure_handler = null;

	/**
	 * Populate-content handler. v3.32 orchestrator dep.
	 *
	 * @var object|null
	 */
	private $populate_content_handler = null;

	/**
	 * Verify handler. v3.32 orchestrator dep.
	 *
	 * @var object|null
	 */
	private $verify_handler = null;

	/**
	 * Media service (exposes smart_search).
	 *
	 * @var object|null
	 */
	private $media_service = null;

	/** @var ImageSideloadService|null */
	private ?ImageSideloadService $sideload_service = null;

	/** @var ReferenceJsonTranslator|null */
	private ?ReferenceJsonTranslator $translator = null;

	/**
	 * Vision provider. Loose-typed (see bricks_service rationale).
	 *
	 * @var object|null
	 */
	private $vision = null;

	/**
	 * Image resolver. Loose-typed (see bricks_service rationale).
	 *
	 * @var object|null
	 */
	private $image_resolver = null;

	/** @var VisionPromptBuilder|null */
	private ?VisionPromptBuilder $prompt_builder = null;

	/** @var VisionResponseMapper|null */
	private ?VisionResponseMapper $response_mapper = null;

	/**
	 * OnboardingService-like object exposing get_business_brief_summary(array).
	 *
	 * @var object|null
	 */
	private $onboarding_service = null;

	/**
	 * Constructor.
	 *
	 * Router-facing signature. All orchestrator deps optional; populated by
	 * make_v32() for the v3.32 pattern-from-image flow.
	 *
	 * @param object                 $bricks_service  Bricks service (loose-typed for test anon stubs).
	 * @param callable               $require_bricks  Callback that returns \WP_Error|null.
	 * @param object|null            $vision          Optional vision provider.
	 * @param object|null            $image_resolver  Optional image resolver.
	 */
	public function __construct(
		$bricks_service,
		callable $require_bricks,
		$vision = null,
		$image_resolver = null
	) {
		$this->bricks_service = $bricks_service;
		$this->require_bricks = $require_bricks;
		$this->vision         = $vision;
		$this->image_resolver = $image_resolver;
	}

	/**
	 * v3.32 canonical construction path. Router wires every dep explicitly.
	 *
	 * @internal
	 */
	public static function make_v32(
		$proposal_service,
		callable $require_bricks,
		$build_structure_handler,
		$populate_content_handler,
		$verify_handler,
		$media_service,
		ImageSideloadService $sideload_service,
		ReferenceJsonTranslator $translator,
		$vision,
		$image_resolver,
		VisionPromptBuilder $prompt_builder,
		VisionResponseMapper $response_mapper,
		$bricks_service,
		$onboarding_service
	): self {
		$h = new self( $bricks_service, $require_bricks, $vision, $image_resolver );
		$h->proposal_service         = $proposal_service;
		$h->build_structure_handler  = $build_structure_handler;
		$h->populate_content_handler = $populate_content_handler;
		$h->verify_handler           = $verify_handler;
		$h->media_service            = $media_service;
		$h->sideload_service         = $sideload_service;
		$h->translator               = $translator;
		$h->prompt_builder           = $prompt_builder;
		$h->response_mapper          = $response_mapper;
		$h->onboarding_service       = $onboarding_service;
		return $h;
	}

	/**
	 * Test-only public entry to the private tool_from_image.
	 *
	 * @internal
	 * @param array<string,mixed> $args Tool args.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function call_tool_from_image( array $args ): array|\WP_Error {
		return $this->tool_from_image( $args );
	}

	/**
	 * Handle a design_pattern tool action.
	 *
	 * @param array<string, mixed> $args Tool arguments (requires: action).
	 * @return array<string, mixed>|\WP_Error Result or error.
	 */
	public function handle( array $args ): array|\WP_Error {
		$bricks_error = ( $this->require_bricks )();
		if ( null !== $bricks_error ) {
			return $bricks_error;
		}

		$action = sanitize_text_field( $args['action'] ?? '' );

		return match ( $action ) {
			'capture'       => $this->tool_capture( $args ),
			'list'          => $this->tool_list( $args ),
			'get'           => $this->tool_get( $args ),
			'create'        => $this->tool_create( $args ),
			'update'        => $this->tool_update( $args ),
			'delete'        => $this->tool_delete( $args ),
			'export'        => $this->tool_export( $args ),
			'import'        => $this->tool_import( $args ),
			'mark_required' => $this->tool_mark_required( $args ),
			'from_image'    => $this->tool_from_image( $args ),
			default         => new \WP_Error(
				'invalid_action',
				sprintf( 'Unknown action "%s". Valid: capture, list, get, create, update, delete, export, import, mark_required, from_image.', $action )
			),
		};
	}

	/**
	 * Capture a pattern from an existing built section.
	 *
	 * Required args: page_id, block_id, name, category.
	 * Optional args: id (auto-generated if missing), tags.
	 */
	private function tool_capture( array $args ): array|\WP_Error {
		$page_id  = (int) ( $args['page_id'] ?? 0 );
		$block_id = sanitize_text_field( $args['block_id'] ?? '' );
		$name     = sanitize_text_field( $args['name'] ?? '' );
		$category = sanitize_text_field( $args['category'] ?? '' );

		foreach ( [ 'page_id' => $page_id, 'block_id' => $block_id, 'name' => $name, 'category' => $category ] as $k => $v ) {
			if ( empty( $v ) ) {
				return new \WP_Error( 'missing_field', sprintf( 'Argument "%s" is required.', $k ) );
			}
		}

		$meta = [
			'id'         => sanitize_key( $args['id'] ?? $this->slugify( $name ) ),
			'name'       => $name,
			'category'   => $category,
			'tags'       => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $args['tags'] ?? [] ) ) ) ),
			'layout'     => sanitize_text_field( $args['layout'] ?? '' ),
			'background' => sanitize_text_field( $args['background'] ?? '' ),
		];

		$validator = new PatternValidator();
		$bricks    = $this->bricks_service;
		$classes   = $this->bricks_service->get_global_class_service();
		$vars      = $this->bricks_service->get_global_variable_service();

		$capture = new PatternCapture( $validator, $bricks, $classes, $vars );
		$result  = $capture->capture( $page_id, $block_id, $meta );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error( $result['error'], $result['message'] ?? 'Capture failed.', $result );
		}

		$saved = DesignPatternService::create( $result );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return [
			'captured' => true,
			'pattern'  => $saved,
		];
	}

	/**
	 * Generate a pattern from an image via server-side vision — v3.32 3-flow orchestrator.
	 *
	 * Flows:
	 *   - image only   → vision->analyze()
	 *   - JSON only    → translator->translate() (text-only)
	 *   - image + JSON → vision->analyze() with reference_json in prompt
	 *
	 * After vision/translation:
	 *   1. Pre-create global_classes_to_create (so ClassIntentResolver sees them).
	 *   2. Sideload image elements via ImageSideloadService.
	 *   3. Save pattern to library (DesignPatternService::create).
	 *   4. If page_id supplied: ProposalService::create → BuildStructureHandler → PopulateContentHandler → VerifyHandler.
	 */
	private function tool_from_image( array $args ): array|\WP_Error {
		$name     = sanitize_text_field( $args['name']     ?? '' );
		$category = sanitize_text_field( $args['category'] ?? '' );
		if ( $name === '' ) {
			return new \WP_Error( 'missing_field', 'Argument "name" is required.' );
		}
		if ( $category === '' ) {
			return new \WP_Error( 'missing_field', 'Argument "category" is required.' );
		}

		$has_image = isset( $args['image_url'] ) || isset( $args['image_id'] ) || isset( $args['image_base64'] );
		$has_json  = isset( $args['reference_json'] ) && is_array( $args['reference_json'] );
		if ( ! $has_image && ! $has_json ) {
			return new \WP_Error( 'missing_input', 'Supply at least one of image_url/image_id/image_base64 OR reference_json.' );
		}

		$dry_run = (bool) ( $args['dry_run'] ?? false );
		$page_id = isset( $args['page_id'] ) ? (int) $args['page_id'] : 0;

		if ( null === $this->bricks_service ) {
			return new \WP_Error( 'bricks_unavailable', 'BricksService not injected.' );
		}
		$classes_svc   = $this->bricks_service->get_global_class_service();
		$variables_svc = $this->bricks_service->get_global_variable_service();

		$site_context = [
			'classes'   => $classes_svc->get_all_by_name(),
			'variables' => $variables_svc->get_all_with_values(),
			'theme'     => $this->infer_theme( $variables_svc->get_all_with_values() ),
		];

		// Route to vision (image) or text-only translator (JSON-only).
		$extracted = null;
		if ( $has_image ) {
			if ( null === $this->vision || null === $this->image_resolver || null === $this->prompt_builder || null === $this->response_mapper ) {
				return new \WP_Error( 'from_image_unavailable', 'Vision pipeline not initialized.' );
			}
			$image = $this->image_resolver->resolve( $args );
			if ( is_wp_error( $image ) ) {
				return $image;
			}

			$reference_json = $has_json ? $args['reference_json'] : null;
			$prompt         = $this->prompt_builder->build_for_schema( $site_context, $reference_json );
			$response       = $this->vision->analyze( $image, $prompt['tool_schema'], $prompt['messages'] );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$extracted = $this->response_mapper->extract_tool_output( $response );
			if ( is_wp_error( $extracted ) ) {
				return $extracted;
			}
		} else {
			// JSON-only path.
			if ( null === $this->translator ) {
				return new \WP_Error( 'translator_unavailable', 'ReferenceJsonTranslator not injected.' );
			}
			$translation = $this->translator->translate( $args['reference_json'], $site_context );
			if ( is_wp_error( $translation ) ) {
				return $translation;
			}
			$extracted = [
				'description'              => $translation['description'],
				'design_plan'              => $translation['design_plan'],
				'global_classes_to_create' => $translation['global_classes_to_create'],
				'content_map'              => $translation['content_map'],
				'usage'                    => $translation['usage'],
			];
		}

		if ( $dry_run ) {
			return [
				'dry_run'            => true,
				'description'        => $extracted['description'],
				'design_plan'        => $extracted['design_plan'],
				'global_classes'     => $extracted['global_classes_to_create'],
				'content_map'        => $extracted['content_map'],
				'vision_cost_tokens' => $extracted['usage'],
			];
		}

		// Pre-create global_classes_to_create → ClassIntentResolver finds them as existing.
		$created_classes = [];
		foreach ( (array) $extracted['global_classes_to_create'] as $cls ) {
			if ( ! is_array( $cls ) ) {
				continue;
			}
			$cls_name = (string) ( $cls['name'] ?? '' );
			$settings = is_array( $cls['settings'] ?? null ) ? $cls['settings'] : [];
			if ( $cls_name === '' ) {
				continue;
			}
			$created = $classes_svc->create_from_payload( [ 'name' => $cls_name, 'settings' => $settings ] );
			if ( $created !== '' ) {
				$created_classes[] = [ 'name' => $cls_name, 'id' => $created ];
			}
		}

		// Sideload images (walks design_plan.elements[] image nodes).
		$business_brief = '';
		if ( null !== $this->onboarding_service && method_exists( $this->onboarding_service, 'get_business_brief_summary' ) ) {
			$business_brief = (string) $this->onboarding_service->get_business_brief_summary( [] );
		}
		$sideload_out = [ 'plan' => $extracted['design_plan'], 'attachment_ids' => [], 'misses' => [] ];
		if ( null !== $this->sideload_service ) {
			$sideload_out             = $this->sideload_service->sideload( $extracted['design_plan'], $business_brief );
			$extracted['design_plan'] = $sideload_out['plan'];
		}

		// v3.32: library save deferred — design_plan shape is not canonical Bricks
		// element-tree shape yet, so persisting it here would create unusable library
		// entries. Skip for from_image/JSON flows; library save can be added in a
		// later milestone once the translator path is solid.
		$pattern_id = '';

		$response_payload = [
			'pattern_id'             => $pattern_id,
			'description'            => $extracted['description'],
			'design_plan'            => $extracted['design_plan'],
			'global_classes_created' => $created_classes,
			'sideloaded_images'      => $sideload_out['attachment_ids'],
			'sideload_misses'        => $sideload_out['misses'],
			'content_map'            => $extracted['content_map'],
			'vision_cost_tokens'     => $extracted['usage'],
		];

		if ( $page_id <= 0 ) {
			return $response_payload;   // Pattern saved, no build.
		}

		// Chain pipeline: ProposalService::create → build_structure → populate_content → verify.
		if ( null === $this->proposal_service ) {
			return $response_payload;
		}
		$proposal = $this->proposal_service->create( $page_id, $extracted['description'], $extracted['design_plan'] );
		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}
		$proposal_id                     = (string) ( $proposal['proposal_id'] ?? '' );
		$response_payload['proposal_id'] = $proposal_id;

		if ( null === $this->build_structure_handler ) {
			return $response_payload;   // No build handler → stop after proposal.
		}

		// BuildStructureHandler::handle requires $args['schema'] — pull it from the
		// proposal result (ProposalService::create returns proposal_id + suggested_schema).
		$schema = $proposal['suggested_schema'] ?? null;
		if ( ! is_array( $schema ) ) {
			return new \WP_Error( 'proposal_incomplete', 'ProposalService did not return suggested_schema.' );
		}

		$build = $this->build_structure_handler->handle( [ 'proposal_id' => $proposal_id, 'schema' => $schema ] );
		if ( is_wp_error( $build ) ) {
			return $build;
		}
		$section_id                     = (string) ( $build['section_id'] ?? '' );
		$response_payload['section_id'] = $section_id;

		if ( $section_id !== '' && null !== $this->populate_content_handler ) {
			$content_map = is_array( $extracted['content_map'] ?? null ) ? $extracted['content_map'] : [];

			// Synthesize a minimal content_map from design_plan.elements[].content_hint when empty.
			// PopulateContentHandler rejects empty maps, and the downstream content_plan pass
			// needs placeholder entries to drive Unsplash image search.
			if ( $content_map === [] ) {
				$synthesized = [];
				foreach ( (array) ( $extracted['design_plan']['elements'] ?? [] ) as $el ) {
					if ( ! is_array( $el ) ) {
						continue;
					}
					$role = (string) ( $el['role'] ?? '' );
					if ( $role === '' ) {
						continue;
					}
					$hint                 = (string) ( $el['content_hint'] ?? $role );
					$synthesized[ $role ] = '[PLACEHOLDER] ' . $hint;
				}
				$content_map = $synthesized !== [] ? $synthesized : [ '_fallback' => '[PLACEHOLDER]' ];
			}

			$populate    = $this->populate_content_handler->handle(
				[
					'section_id'  => $section_id,
					'content_map' => $content_map,
				]
			);
			if ( is_wp_error( $populate ) ) {
				return $populate;
			}
			$response_payload['populate_result'] = $populate;

			if ( null !== $this->verify_handler ) {
				$verify = $this->verify_handler->handle( [ 'page_id' => $page_id ] );
				if ( is_wp_error( $verify ) ) {
					return $verify;
				}
				$response_payload['verification'] = $verify;
			}
		}

		return $response_payload;
	}

	/**
	 * Infer theme hint (dark/light) from variable palette.
	 *
	 * @param array<string, mixed> $site_vars name => value map.
	 */
	private function infer_theme( array $site_vars ): string {
		foreach ( $site_vars as $name => $value ) {
			$lname = strtolower( (string) $name );
			if ( str_contains( $lname, 'base-ultra-dark' ) || str_contains( $lname, 'base-dark' ) ) {
				return 'dark';
			}
		}
		return 'light';
	}

	/**
	 * Convert a human-readable string to a URL-safe slug.
	 */
	private function slugify( string $s ): string {
		$s = strtolower( $s );
		$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
		return trim( (string) $s, '-' );
	}

	/**
	 * List all patterns with optional filters.
	 */
	private function tool_list( array $args ): array {
		$all = DesignPatternService::list_all();

		// Filter by category.
		$category = $args['category'] ?? '';
		if ( '' !== $category ) {
			$all = array_values( array_filter( $all, fn( $p ) => is_array( $p ) && ( $p['category'] ?? '' ) === $category ) );
		}

		// Filter by tags (pattern must have ALL specified tags).
		$tags = $args['tags'] ?? [];
		if ( is_array( $tags ) && ! empty( $tags ) ) {
			$all = array_values( array_filter( $all, function ( $p ) use ( $tags ) {
				if ( ! is_array( $p ) ) {
					return false;
				}
				$ptags = $p['tags'] ?? [];
				if ( ! is_array( $ptags ) ) {
					return false;
				}
				foreach ( $tags as $t ) {
					if ( ! in_array( $t, $ptags, true ) ) {
						return false;
					}
				}
				return true;
			} ) );
		}

		return [
			'total'    => count( $all ),
			'patterns' => $all,
		];
	}

	/**
	 * Get a single pattern by ID.
	 *
	 * Optional args:
	 *   include_drift (bool) — attach drift_report to response (default false).
	 */
	private function tool_get( array $args ): array|\WP_Error {
		$id = sanitize_text_field( $args['id'] ?? '' );
		if ( '' === $id ) {
			return new \WP_Error( 'missing_id', 'id is required.' );
		}

		$pattern = DesignPatternService::get( $id );
		if ( null === $pattern ) {
			return new \WP_Error( 'not_found', sprintf( 'Pattern "%s" not found.', $id ) );
		}

		// v3.29: optional drift report.
		$include_drift = ! empty( $args['include_drift'] );
		if ( $include_drift ) {
			$pattern['drift_report'] = $this->compute_drift( $pattern );
		}

		return $pattern;
	}

	/**
	 * Compute drift report for a pattern. Cached in transient for 60s.
	 *
	 * Reuses the already-injected BricksService to obtain GlobalClassService,
	 * avoiding redundant instantiation of BricksCore.
	 *
	 * @param array<string, mixed> $pattern Full pattern array.
	 * @return array<string, mixed> Drift report from PatternDriftDetector::detect().
	 */
	private function compute_drift( array $pattern ): array {
		$cache_key = 'bricks_mcp_pattern_drift_' . ( $pattern['id'] ?? '' );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$class_service = $this->bricks_service->get_global_class_service();
		$site_classes  = $class_service->get_all_by_name();

		$detector = new \BricksMCP\MCP\Services\PatternDriftDetector();
		$report   = $detector->detect( $pattern, $site_classes );

		set_transient( $cache_key, $report, 60 );
		return $report;
	}

	/**
	 * Create a new pattern in the database.
	 */
	private function tool_create( array $args ): array|\WP_Error {
		$pattern = $args['pattern'] ?? null;
		if ( ! is_array( $pattern ) ) {
			return new \WP_Error( 'missing_pattern', 'pattern object is required. Must have id, name, category, tags.' );
		}

		$result = DesignPatternService::create( $pattern );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'created' => true,
			'pattern' => $result,
		];
	}

	/**
	 * Update an existing pattern.
	 */
	private function tool_update( array $args ): array|\WP_Error {
		$id = sanitize_text_field( $args['id'] ?? '' );
		if ( '' === $id ) {
			return new \WP_Error( 'missing_id', 'id is required.' );
		}

		$updates = $args['pattern'] ?? null;
		if ( ! is_array( $updates ) || empty( $updates ) ) {
			return new \WP_Error( 'missing_pattern', 'pattern object with fields to update is required.' );
		}

		$result = DesignPatternService::update( $id, $updates );

		// v3.29: clear own drift transient so next get reflects the updated pattern.
		if ( ! is_wp_error( $result ) ) {
			delete_transient( 'bricks_mcp_pattern_drift_' . $id );
		}

		return $result;
	}

	/**
	 * Delete a pattern.
	 */
	private function tool_delete( array $args ): array|\WP_Error {
		$id = sanitize_text_field( $args['id'] ?? '' );
		if ( '' === $id ) {
			return new \WP_Error( 'missing_id', 'id is required.' );
		}

		// Check if pattern exists before requesting confirmation.
		$existing = DesignPatternService::get( $id );
		if ( null === $existing ) {
			return new \WP_Error( 'not_found', sprintf( 'Pattern "%s" not found.', $id ) );
		}

		$action_desc = sprintf( 'Delete pattern "%s" (%s).', $id, $existing['name'] ?? '' );

		// Destructive action confirmation flow.
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'bricks_mcp_confirm_required', $action_desc );
		}

		return DesignPatternService::delete( $id );
	}

	/**
	 * Export patterns as portable JSON.
	 */
	private function tool_export( array $args ): array {
		$ids = $args['ids'] ?? [];
		if ( ! is_array( $ids ) ) {
			$ids = [];
		}

		$patterns = DesignPatternService::export( $ids );

		return [
			'exported_count' => count( $patterns ),
			'patterns'       => $patterns,
			'note'           => empty( $ids )
				? 'Exported all patterns. Pass ids array to export specific patterns.'
				: sprintf( 'Exported %d pattern(s) by ID.', count( $patterns ) ),
		];
	}

	/**
	 * Import patterns from a JSON array.
	 */
	private function tool_import( array $args ): array|\WP_Error {
		$patterns = $args['patterns'] ?? null;
		if ( ! is_array( $patterns ) || empty( $patterns ) ) {
			return new \WP_Error( 'missing_patterns', 'patterns array is required. Each item must be a pattern object with id, name, category, tags.' );
		}

		return DesignPatternService::import( $patterns );
	}

	/**
	 * Mark a role as required (or unmark) on a pattern.
	 *
	 * Walks the pattern's structure tree, finds all nodes with matching `role`,
	 * sets `required` flag. Re-computes checksum + persists.
	 */
	private function tool_mark_required( array $args ): array|\WP_Error {
		$pattern_id = sanitize_text_field( $args['pattern_id'] ?? '' );
		$role       = sanitize_text_field( $args['role'] ?? '' );
		$required   = (bool) ( $args['required'] ?? true );

		if ( $pattern_id === '' ) {
			return new \WP_Error( 'missing_field', 'Argument "pattern_id" is required.' );
		}
		if ( $role === '' ) {
			return new \WP_Error( 'missing_field', 'Argument "role" is required.' );
		}

		$pattern = \BricksMCP\MCP\Services\DesignPatternService::get( $pattern_id );
		if ( null === $pattern ) {
			return new \WP_Error( 'pattern_not_found', sprintf( 'Pattern "%s" not found.', $pattern_id ) );
		}

		$nodes_updated   = 0;
		$available_roles = [];
		$walker = function ( array &$node ) use ( $role, $required, &$nodes_updated, &$available_roles, &$walker ) {
			if ( isset( $node['role'] ) && is_string( $node['role'] ) && $node['role'] !== '' ) {
				$available_roles[] = $node['role'];
				if ( $node['role'] === $role ) {
					if ( $required ) {
						$node['required'] = true;
					} else {
						unset( $node['required'] );
					}
					$nodes_updated++;
				}
			}
			if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
				foreach ( $node['children'] as &$child ) {
					if ( is_array( $child ) ) {
						$walker( $child );
					}
				}
				unset( $child );
			}
		};

		if ( isset( $pattern['structure'] ) && is_array( $pattern['structure'] ) ) {
			$walker( $pattern['structure'] );
		}

		if ( $nodes_updated === 0 ) {
			return new \WP_Error(
				'role_not_in_pattern',
				sprintf( 'Role "%s" not found in pattern "%s".', $role, $pattern_id ),
				[ 'available_roles' => array_values( array_unique( $available_roles ) ) ]
			);
		}

		// Recompute checksum.
		if ( class_exists( '\BricksMCP\MCP\Services\PatternValidator' ) ) {
			$pattern['checksum'] = ( new \BricksMCP\MCP\Services\PatternValidator() )->checksum( $pattern );
		}

		$updated = \BricksMCP\MCP\Services\DesignPatternService::update( $pattern_id, $pattern );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		// Clear drift transient (pattern changed).
		delete_transient( 'bricks_mcp_pattern_drift_' . $pattern_id );

		return [
			'updated'       => true,
			'pattern_id'    => $pattern_id,
			'role'          => $role,
			'required'      => $required,
			'nodes_updated' => $nodes_updated,
			'checksum'      => $updated['checksum'] ?? ( $pattern['checksum'] ?? '' ),
		];
	}

	/**
	 * Register the design_pattern tool.
	 *
	 * @param ToolRegistry $registry Tool registry instance.
	 */
	public function register( ToolRegistry $registry ): void {
		$registry->register(
			'design_pattern',
			__( "Manage design patterns \u2014 reusable section compositions for the build pipeline.\n\nActions: capture, list, get, create, update, delete, export, import, mark_required, from_image.\n\nUse capture to snapshot an existing built section into the pattern library. Use from_image to create a pattern via vision/JSON translation — supports three input modes:\n  - image only: vision reads the image, emits design_plan; classes auto-created via ClassIntentResolver; images auto-sideloaded via media:smart_search + business_brief.\n  - reference_json only: text-only Claude translates foreign classes \u2192 site BEM, foreign var(--brxw-*) \u2192 site variables.\n  - image + reference_json: vision translates + adapts content per image language/intent.\nAt least one of image_*/reference_json is required. When page_id is supplied, the pattern is ALSO built on that page via the existing propose_design \u2192 build_structure \u2192 populate_content \u2192 verify_build pipeline. Set dry_run=true for a preview without saving/building.\n\nAll patterns live in the database (managed via admin UI or MCP). Use export/import for cross-site sharing.", 'bricks-mcp' ),
			[
				'type'       => 'object',
				'properties' => [
					'action'   => [
						'type'        => 'string',
						'enum'        => [ 'capture', 'list', 'get', 'create', 'update', 'delete', 'export', 'import', 'mark_required', 'from_image' ],
						'description' => __( 'Action to perform', 'bricks-mcp' ),
					],
					'page_id'  => [
						'type'        => 'integer',
						'description' => __( 'Page ID. capture: required (section source). from_image: optional — when supplied, pattern is auto-built on that page via the normal pipeline; when omitted, pattern is saved to library only.', 'bricks-mcp' ),
					],
					'block_id' => [
						'type'        => 'string',
						'description' => __( 'Element ID of the section root (capture: required)', 'bricks-mcp' ),
					],
					'name'     => [
						'type'        => 'string',
						'description' => __( 'Human-readable pattern name (capture: required)', 'bricks-mcp' ),
					],
					'id'       => [
						'type'        => 'string',
						'description' => __( 'Pattern ID (capture: optional auto-slug from name; get, update, delete: required)', 'bricks-mcp' ),
					],
					'pattern'  => [
						'type'        => 'object',
						'description' => __( 'Pattern object (create: required with id/name/category/tags, update: fields to change)', 'bricks-mcp' ),
					],
					'patterns' => [
						'type'        => 'array',
						'description' => __( 'Array of pattern objects (import: required)', 'bricks-mcp' ),
					],
					'ids'      => [
						'type'        => 'array',
						'description' => __( 'Pattern IDs to export (export: optional)', 'bricks-mcp' ),
					],
					'category' => [
						'type'        => 'string',
						'description' => __( 'Pattern category (capture: required; list: optional filter)', 'bricks-mcp' ),
					],
					'tags'     => [
						'type'        => 'array',
						'description' => __( 'Pattern tags (capture: optional; list: optional filter)', 'bricks-mcp' ),
					],
					'layout' => [
						'type'        => 'string',
						'enum'        => [ 'centered', 'split-60-40', 'split-50-50', 'grid-2', 'grid-3', 'grid-4', 'stacked' ],
						'description' => __( 'Layout shape (capture: optional, default inferred from structure)', 'bricks-mcp' ),
					],
					'background' => [
						'type'        => 'string',
						'enum'        => [ 'dark', 'light' ],
						'description' => __( 'Background tone (capture: optional, default inferred from section _background color)', 'bricks-mcp' ),
					],
					'role' => [
						'type'        => 'string',
						'description' => __( 'Role name to mark/unmark required (mark_required: required)', 'bricks-mcp' ),
					],
					'required' => [
						'type'        => 'boolean',
						'description' => __( 'Mark role as required (true) or unmark (false). Default true. (mark_required: optional)', 'bricks-mcp' ),
					],
					'include_drift' => [
						'type'        => 'boolean',
						'description' => __( 'Include drift_report in response (get: optional, default false)', 'bricks-mcp' ),
					],
					'image_url' => [
						'type'        => 'string',
						'description' => __( 'HTTPS image URL (from_image: one of image_*/reference_json required)', 'bricks-mcp' ),
					],
					'image_id' => [
						'type'        => 'integer',
						'description' => __( 'WP media attachment ID (from_image: alternative to image_url/image_base64)', 'bricks-mcp' ),
					],
					'image_base64' => [
						'type'        => 'string',
						'description' => __( 'Raw base64-encoded image bytes (from_image: alternative to image_url/image_id)', 'bricks-mcp' ),
					],
					'reference_json' => [
						'type'        => 'object',
						'description' => __( 'Bricksies clipboard JSON (from_image: authoritative template for translation — site-BEM rename + var(--brxw-*) \u2192 site variables). Alternative to image_*. When supplied WITH image_*, both drive translation.', 'bricks-mcp' ),
					],
					'dry_run' => [
						'type'        => 'boolean',
						'description' => __( 'Preview only — vision runs, but no save/build side-effects (from_image: optional, default false)', 'bricks-mcp' ),
					],
				],
				'required'   => [ 'action' ],
			],
			[ $this, 'handle' ]
		);
	}
}
