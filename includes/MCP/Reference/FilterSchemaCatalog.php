<?php
/**
 * FilterSchemaCatalog reference data.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP\Reference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * FilterSchemaCatalog class.
 *
 * Provides the static reference array for filter schema.
 */
final class FilterSchemaCatalog {

	/**
	 * Return the reference data array.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		return array(
			'filters_enabled'     => null,
			'enable_filters_hint' => 'Query filters must be enabled in Bricks > Settings > Performance > "Enable query sort / filter / live search". Without this, filter elements render as empty.',
			'filter_elements'     => array(
				'filter-checkbox'       => array(
					'label'           => 'Filter - Checkbox',
					'supports_source' => array( 'taxonomy', 'wpField', 'customField' ),
					'required'        => array( 'filterQueryId', 'filterSource' ),
					'bricks_2_3'     => array(
						'show_more_less' => array(
							'description'  => 'Load more / show less button for long option lists (@since 2.3).',
							'limitOptions' => 'Number of visible options before "Show more" button appears (number). Leave empty to show all.',
							'showMoreText' => 'Custom "Show more" button text. Use %number% placeholder for count of hidden items. Default: "Show %number% more".',
							'showLessText' => 'Custom "Show less" button text. Default: "Show Less".',
							'styling_note' => 'Button styling controls: showMoreButtonSize, showMoreButtonStyle, showMoreButtonOutline, showMoreButtonTypography, showMoreButtonBackground, showMoreButtonBorder.',
						),
						'countAlignEnd'  => 'Align item count to end of row (checkbox, requires displayMode=default). @since 2.3.',
					),
				),
				'filter-radio'          => array(
					'label'           => 'Filter - Radio',
					'supports_source' => array( 'taxonomy', 'wpField', 'customField' ),
					'supports_action' => array( 'filter', 'sort', 'per_page' ),
					'required'        => array( 'filterQueryId', 'filterSource' ),
					'bricks_2_3'     => array(
						'show_more_less' => array(
							'description'  => 'Load more / show less button for long option lists (@since 2.3).',
							'limitOptions' => 'Number of visible options before "Show more" button appears (number). Leave empty to show all.',
							'showMoreText' => 'Custom "Show more" button text. Use %number% placeholder for count of hidden items. Default: "Show %number% more".',
							'showLessText' => 'Custom "Show less" button text. Default: "Show Less".',
							'styling_note' => 'Button styling controls: showMoreButtonSize, showMoreButtonStyle, showMoreButtonOutline, showMoreButtonTypography, showMoreButtonBackground, showMoreButtonBorder.',
						),
						'countAlignEnd'  => 'Align item count to end of row (checkbox, requires displayMode=default). @since 2.3.',
					),
				),
				'filter-select'         => array(
					'label'           => 'Filter - Select',
					'supports_source' => array( 'taxonomy', 'wpField', 'customField' ),
					'supports_action' => array( 'filter', 'sort', 'per_page' ),
					'required'        => array( 'filterQueryId', 'filterSource' ),
					'bricks_2_3'     => array(
						'choices_js' => array(
							'description'     => 'Enhanced select powered by Choices.js library (@since 2.3). Adds search, multiple selection, and advanced styling.',
							'choicesJs'       => 'Enable enhanced select (checkbox). Requires filterAction=filter or empty.',
							'choicesPosition' => 'Dropdown position: auto (default) | bottom | top.',
							'search'          => array(
								'choicesSearch'            => 'Enable search within dropdown (checkbox).',
								'choicesSearchPlaceholder' => 'Search input placeholder text. Default: "Search".',
								'choicesNoResultsText'     => 'Text shown when search returns no results. Default: "No results found".',
								'choicesNoChoicesText'     => 'Text shown when no choices exist or all are selected.',
								'styling_note'             => 'Styling: choicesSearchBackground, choicesSearchTypography, choicesSearchInputTypography, choicesSearchInputPadding.',
							),
							'multiple'        => array(
								'enableMultiple'   => 'Enable multiple option selection (checkbox). Requires choicesJs + filterAction=filter.',
								'filterMultiLogic' => 'Logic for combining multiple values (now requires choicesJs + enableMultiple in 2.3).',
								'styling_note'     => 'Pill styling: choicesPillGap, choicesPillBackground, choicesPillBorder, choicesPillTypography.',
							),
							'styling_note'    => 'General: choicesPadding, choicesBackgroundColor, choicesBorderBase, choicesBorderColor, choicesBorderRadius, choicesFontSize, choicesTextColor, choicesArrowColor. Item: choicesItemPadding, choicesDropdownBackground, choicesHighlightBackground, choicesHighlightTextColor, choicesDisabledBackground, choicesDisabledTextColor.',
						),
					),
				),
				'filter-search'         => array(
					'label'           => 'Filter - Search (Live Search)',
					'supports_source' => array(),
					'required'        => array( 'filterQueryId' ),
				),
				'filter-range'          => array(
					'label'           => 'Filter - Range (slider)',
					'supports_source' => array( 'taxonomy', 'wpField', 'customField' ),
					'required'        => array( 'filterQueryId', 'filterSource' ),
					'bricks_2_3'     => array(
						'decimalPlaces'         => 'Number of decimal places to display (number, default: 0). Applies to both slider and input modes.',
						'inputUseCustomStepper' => 'Show custom +/- stepper buttons (checkbox, requires displayMode=input). Renders increment/decrement buttons next to min and max inputs.',
						'stepper_styling_note'  => 'Stepper styling controls (require inputUseCustomStepper): inputStepperGap, inputStepperMarginStart, inputStepperBackground, inputStepperBorder, inputStepperTypography.',
					),
				),
				'filter-datepicker'     => array(
					'label'           => 'Filter - Datepicker',
					'supports_source' => array( 'wpField', 'customField' ),
					'required'        => array( 'filterQueryId', 'filterSource' ),
					'bricks_2_3'     => array(
						'dateFormat' => 'Custom display format for the datepicker using flatpickr tokens (e.g. "d/m/Y", "M j, Y"). Default: "Y-m-d". Only affects the visual display — database storage format is determined separately. @since 2.3.',
					),
				),
				'filter-submit'         => array(
					'label'           => 'Filter - Submit button',
					'supports_source' => array(),
					'required'        => array( 'filterQueryId' ),
					'note'            => 'Use when filterApplyOn=click on other filters. Triggers filter application.',
				),
				'filter-active-filters' => array(
					'label'           => 'Filter - Active Filters display',
					'supports_source' => array(),
					'required'        => array( 'filterQueryId' ),
					'note'            => 'Shows currently active filters as removable tags.',
				),
			),
			'common_settings'     => array(
				'filterQueryId'  => 'Element ID of the target query loop element (the container/posts element with hasLoop: true)',
				'filterSource'   => 'taxonomy | wpField | customField — what data type to filter by',
				'filterAction'   => 'filter (default) | sort | per_page — what the filter does',
				'filterApplyOn'  => 'change (default, instant) | click (requires filter-submit element)',
				'filterNiceName' => 'URL parameter name (optional, e.g. "_color"). Use unique prefix to avoid conflicts.',
				'filterTaxonomy' => 'Taxonomy slug when filterSource=taxonomy, e.g. "category"',
				'wpPostField'    => 'WordPress post field when filterSource=wpField: post_id | post_date | post_author | post_type | post_status | post_modified',
			),
			'filterQueryId_note'  => 'filterQueryId must be the 6-character Bricks element ID of the query loop container, NOT a post ID. Get it from the element array (element["id"]).',
			'workflow_example'    => array(
				'1. Create posts query loop'     => 'Add container element with hasLoop:true and query.objectType:post, query.post_type:["post"]',
				'2. Note the element ID'         => 'The container element ID (e.g. "abc123") is the filterQueryId for all filters on this page',
				'3. Add filter elements'         => 'Add filter-checkbox, set filterQueryId="abc123", filterSource="taxonomy", filterTaxonomy="category"',
				'4. Enable in Bricks settings'   => 'Bricks > Settings > Performance > Enable query sort / filter / live search',
				'5. Rebuild index'               => 'Bricks automatically indexes on post save. May need manual reindex after enabling.',
			),
		);
	}
}
