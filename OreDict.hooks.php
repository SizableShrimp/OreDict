<?php
/**
 * OreDict hooks file
 * Defines entry points to the extension
 *
 * @file
 * @ingroup Extensions
 * @version 1.0.1
 * @author Jinbobo <paullee05149745@gmail.com>
 * @license
 */

class OreDictHooks {
	/**
	 * Setups and Modifies Database Information
	 *
	 * @access	public
	 * @param	object	DatabaseUpdater Object
	 * @return	boolean	true
	 */
	public static function SchemaUpdate($updater) {
		$extDir = __DIR__;
		$updater->addExtensionUpdate(['addTable', 'ext_oredict_items', "{$extDir}/install/sql/ext_oredict_items.sql", true]);
		$updater->addExtensionUpdate(['dropField', 'ext_oredict_items', 'flags', "{$extDir}/upgrade/sql/remove_flags.sql", true]);
		return true;
	}

	/**
	 * Entry point for parser functions.
	 *
	 * @param Parser $parser
	 * @return bool
	 */
	public static function SetupParser(Parser &$parser) {
		$parser->setFunctionHook('dict', 'OreDictHooks::RenderParser');
		$parser->setFunctionHook('grid_foreach', 'OreDictHooks::RenderMultiple');
		return true;
	}

	/**
	 * Generate grids from a string.
	 *
	 * @param Parser $parser
	 * @return array|string
	 */
	public static function RenderMultiple(Parser &$parser) {
		$opts = array();
		for ($i = 1; $i < func_num_args(); $i++) {
			$opts[] = func_get_arg($i);
		}

		// Check if input is in the correct format
		foreach ($opts as $opt) {
			if (strpos("{{", $opt) !== false || strpos("}}", $opt) !== false) {
				OreDictError::error(wfMessage('oredict-grid_foreach-format-error')->text());
				return "";
			}
		}

		// Check if separated by commas
		if (strpos($opts[0], ',') !== false) {
			$opts = explode(',', $opts[0]);
		}

		// Check for global parameters
		$gParams = array();
		foreach ($opts as $option) {
			$pair = explode('=>', $option);
			if (count($pair) == 2) {
				$gParams[trim($pair[0])] = trim($pair[1]);
			}
		}

		// Prepare items
		$items = array();
		foreach ($opts as $option) {
			if (strpos($option, '=>') === false) {
				// Pre-load global params
				$items[] = $gParams;
				end($items);
				$iKey = key($items);

				// Parse string
				$gridOptions = explode('!', $option);
				foreach ($gridOptions as $key => $gridOption) {
					$pair = explode('=', $gridOption);
					if (count($pair) == 2) {
						$gridOptions[trim($pair[0])] = trim($pair[1]);
						$items[$iKey][trim($pair[0])] = trim($pair[1]);
					} else {
						$items[$iKey][$key + 1] = trim($gridOption);
					}
				}
			}
		}

		// Create grids
		$outs = array();
		foreach ($items as $options) {
			// Call OreDict
			$outs[] = self::runHooks($options);
		}

		$ret = "";
		foreach ($outs as $out) {
			if (!isset($out[0])) {
				continue;
			}
			$ret .= $out[0];
		}

		// Return output
		return array($ret, 'noparse' => false, 'isHTML' => false);
	}

	/**
	 * Query OreDict and return output.
	 *
	 * @param Parser $parser
	 * @return array
	 */
	public static function RenderParser(Parser &$parser) {
		$opts = array();
		for ($i = 1; $i < func_num_args(); $i++) {
			$opts[] = func_get_arg($i);
		}
		$options = OreDictHooks::ExtractOptions($opts);

		// Call OreDict
		return self::runHooks($options);
	}

	/**
	 * Helper function to extract options from raw parser function input.
	 *
	 * @param array $opts
	 * @return array|bool
	 */
	public static function ExtractOptions($opts) {
		if (count($opts) == 0) return array();
		foreach ($opts as $key => $option) {
			$pair = explode('=', $option);
			if (count($pair) == 2) {
				$name = trim($pair[0]);
				$value = trim($pair[1]);
				$results[$name] = $value;
			} else {
				$results[$key + 1] = trim($option);
			}
		}

		return isset($results) ? $results : false;
	}

	/**
	 * Helper function to split a parameter string into an array.
	 *
	 * @param string $params
	 * @return array
	 */
	public static function ParseParamString($params) {
		if ($params === "") {
			return [];
		}
		return OreDictHooks::ExtractOptions(explode('|', $params));
	}

	/**
	 * Helper function to rebuild a parameter string from an array.
	 *
	 * @param array $params
	 * @return string
	 */

	public static function BuildParamString($params) {
		foreach ($params as $key => $value) {
			$pairs[] = "$key=$value";
		}
		if (!isset($pairs)) {
			return "";
		}
		return implode("|", $pairs);
	}

	/**
	 * Entry point for the EditPage::showEditForm:initial hook, allows the oredict extension to modify the edit form. Displays errors on preview.
	 *
	 * @param EditPage $editPage
	 * @param OutputPage $out
	 * @return bool
	 */
	public static function OutputWarnings(EditPage &$editPage, OutputPage &$out) {
		global $wgOreDictDebug;

		// Output errors
		$errors = new OreDictError($wgOreDictDebug);
		$editPage->editFormTextAfterWarn .= $errors->output();

		return true;
	}

	private static function getOptions($options) {
		// Set mod
		$mod = '';
		if (isset($options['mod'])) {
			$mod = $options['mod'];
		}

		// Set limit
		if (isset($options['limit'])) {
			$limit = $options['limit'];
		}
		if (!isset($limit) || $limit <= 0) {
			$limit = 20;
		}

		return array($mod, $limit);
	}

	private static function runHooks($options) {
		list ($mod, $limit) = self::getOptions($options);

		$dict = new OreDict($options[1], $mod, $limit);
		$dict->exec(isset($options['tag']), isset($options['no-fallback']));
		return $dict->runHooks(self::BuildParamString($options));
	}
}
