<?php
/**
 * @package    CleverStyle CMS
 * @subpackage Builder
 * @author     Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright  Copyright (c) 2011-2015, Nazar Mokrynskyi
 * @license    MIT License, see license.txt
 */
namespace cs;
use
	h,
	Phar;

class Builder {
	/**
	 * @var string
	 */
	protected $root;
	/**
	 * @var string
	 */
	protected $target;
	/**
	 * @param string $root
	 * @param string $target
	 */
	function __construct ($root, $target) {
		$this->root   = $root;
		$this->target = $target;
		if (!file_exists($target)) {
			mkdir($target);
		}
	}
	/**
	 * @return string
	 */
	function form () {
		return h::{'form[method=post]'}(
			h::nav(
				'Build: '.
				h::{'radio.build-mode[name=mode]'}(
					[
						'value'   => ['core', 'module', 'plugin', 'theme'],
						'in'      => ['Core', 'Module', 'Plugin', 'Theme'],
						'onclick' => 'change_mode(this.value, this);'
					]
				)
			).
			h::{'table tr| td'}(
				[
					'Modules',
					'Plugins',
					'Themes'
				],
				[
					h::{'select#modules[name=modules[]][size=20][multiple] option'}(
						array_map(
							function ($module) {
								return [
									$module,
									file_exists("$this->root/components/modules/$module/meta.json") ? [
										'title' => 'Version: '.file_get_json("$this->root/components/modules/$module/meta.json")['version']
									] : [
										'title' => 'No meta.json file found',
										'disabled'
									]
								];
							},
							array_values(
								array_filter(
									get_files_list("$this->root/components/modules", false, 'd'),
									function ($module) {
										return $module != 'System';
									}
								)
							)
						)
					),
					h::{'select#plugins[name=plugins[]][size=20][multiple] option'}(
						array_map(
							function ($plugin) {
								return [
									$plugin,
									file_exists("$this->root/components/plugins/$plugin/meta.json") ? [
										'title' => 'Version: '.file_get_json("$this->root/components/plugins/$plugin/meta.json")['version']
									] : [
										'title' => 'No meta.json file found',
										'disabled'
									]
								];
							},
							get_files_list("$this->root/components/plugins", false, 'd')
						)
					),
					h::{'select#themes[name=themes[]][size=20][multiple] option'}(
						array_map(
							function ($theme) {
								return [
									$theme,
									file_exists("$this->root/themes/$theme/meta.json") ? [
										'title' => 'Version: '.file_get_json("$this->root/themes/$theme/meta.json")['version']
									] : [
										'title' => 'No meta.json file found',
										'disabled'
									]
								];
							},
							array_values(
								array_filter(
									get_files_list("$this->root/themes", false, 'd'),
									function ($theme) {
										return $theme != 'CleverStyle';
									}
								)
							)
						)
					)
				]
			).
			h::{'input[name=suffix]'}(
				[
					'placeholder' => 'Package file suffix'
				]
			).
			h::{'button.uk-button.license'}(
				'License',
				[
					'onclick' => "window.open('license.txt', 'license', 'location=no')"
				]
			).
			h::{'button.uk-button[type=submit]'}(
				'Build'
			)
		);
	}
	/**
	 * @param string[]    $modules
	 * @param string[]    $plugins
	 * @param string[]    $themes
	 * @param null|string $suffix
	 *
	 * @return string
	 */
	function core ($modules = [], $plugins = [], $themes = [], $suffix = null) {
		$modules     = $modules ?: [];
		$plugins     = $plugins ?: [];
		$themes      = $themes ?: [];
		$suffix      = $suffix ? "_$suffix" : '';
		$version     = file_get_json("$this->root/components/modules/System/meta.json")['version'];
		$target_file = "$this->target/CleverStyle_CMS_$version$suffix.phar.php";
		if (file_exists($target_file)) {
			unlink($target_file);
		}
		$phar = new Phar($target_file);
		unset($target_file);
		$phar->startBuffering();
		$length = strlen("$this->root/");
		foreach (get_files_list("$this->root/install", false, 'f', true, true) as $file) {
			$phar->addFile($file, substr($file, $length));
		}
		unset($file);
		/**
		 * Files to be included into installation package
		 */
		$core_files = $this->get_files(
			[
				"$this->root/components/modules/System",
				"$this->root/components/blocks/.gitkept",
				"$this->root/components/plugins/.gitkept",
				"$this->root/core",
				"$this->root/custom",
				"$this->root/includes",
				"$this->root/templates",
				"$this->root/themes/CleverStyle",
				"$this->root/composer.json",
				"$this->root/composer.lock",
				"$this->root/index.php",
				"$this->root/license.txt",
				"$this->root/Storage.php"
			]
		);
		/**
		 * Add modules that should be built-in into package
		 */
		$components_files = [];
		$modules          = array_filter(
			$modules,
			function ($module) use (&$components_files) {
				return $this->get_component_files("$this->root/components/modules/$module", $components_files);
			}
		);
		sort($modules);
		$phar->addFromString('modules.json', _json_encode($modules));
		/**
		 * Add plugins that should be built-in into package
		 */
		$plugins = array_filter(
			$plugins,
			function ($plugin) use (&$components_files) {
				return $this->get_component_files("$this->root/components/plugins/$plugin", $components_files);
			}
		);
		sort($plugins);
		$phar->addFromString('plugins.json', _json_encode($plugins));
		/**
		 * Add themes that should be built-in into package
		 */
		$themes   = array_filter(
			$themes,
			function ($theme) use (&$components_files) {
				return $this->get_component_files("$this->root/themes/$theme", $components_files);
			}
		);
		$themes[] = 'CleverStyle';
		sort($themes);
		$phar->addFromString('themes.json', _json_encode($themes));
		/**
		 * Joining system and components files
		 */
		$core_files = array_merge(
			$core_files,
			$components_files
		);
		/**
		 * Addition of files into package
		 */
		foreach ($core_files as $index => &$file) {
			$phar->addFile($file, "fs/$index");
			$file = substr($file, $length);
		}
		unset($index, $file);
		/**
		 * Addition of separate files into package
		 */
		$phar->addFromString('fs/'.count($core_files), $this->get_readme());
		$core_files[] = 'readme.html';
		$phar->addFromString(
			'languages.json',
			_json_encode(
				array_merge(
					_substr(get_files_list("$this->root/core/languages", '/^.*?\.php$/i', 'f'), 0, -4) ?: [],
					_substr(get_files_list("$this->root/core/languages", '/^.*?\.json$/i', 'f'), 0, -5) ?: []
				)
			)
		);
		$phar->addFromString(
			'db_engines.json',
			_json_encode(
				_substr(get_files_list("$this->root/core/engines/DB", '/^[^_].*?\.php$/i', 'f'), 0, -4)
			)
		);
		/**
		 * Fixation of system files list (without components files), it is needed for future system updating
		 */
		$phar->addFromString(
			'fs/'.count($core_files),
			_json_encode(
				array_flip(
					array_diff(
						$core_files,
						_substr($components_files, $length)
					)
				)
			)
		);
		$core_files[] = 'core/fs.json';
		unset($components_files, $length);
		/**
		 * Addition of files, that are needed only for installation
		 */
		$phar->addFromString('fs/'.count($core_files), $this->get_htaccess());
		$core_files[] = '.htaccess';
		$phar->addFile("$this->root/config/main.php", 'fs/'.count($core_files));
		$core_files[] = 'config/main.php';
		$phar->addFile("$this->root/favicon.ico", 'fs/'.count($core_files));
		$core_files[] = 'favicon.ico';
		$phar->addFile("$this->root/.gitignore", 'fs/'.count($core_files));
		$core_files[] = '.gitignore';
		/**
		 * Flip array to have direct access to files by name during extracting and installation, and fixing of files list for installation
		 */
		$phar->addFromString(
			'fs.json',
			_json_encode(
				array_flip($core_files)
			)
		);
		unset($core_files);
		/**
		 * Addition of supplementary files, that are needed directly for installation process: installer with GUI interface, readme, license, some additional
		 * information about available languages, themes, current version of system
		 */
		$phar->addFile("$this->root/install.php", 'install.php');
		$phar->addFromString('readme.html', $this->get_readme());
		$phar->addFile("$this->root/license.txt", 'license.txt');
		$phar->addFile("$this->root/components/modules/System/meta.json", 'meta.json');
		$phar->setStub(
			"<?php
if (PHP_SAPI == 'cli') {
	Phar::mapPhar('cleverstyle_cms.phar');
	include 'phar://cleverstyle_cms.phar/install.php';
} else {
	Phar::webPhar(null, 'install.php');
}
__HALT_COMPILER();"
		);
		$phar->stopBuffering();
		return "Done! CleverStyle CMS $version";
	}
	/**
	 * Get array of files
	 *
	 * @param string[] $source Files and directories (absolute paths); If file does non exists - it will be skipped, if directory - all files will be returned
	 *                         instead
	 *
	 * @return array
	 */
	protected function get_files ($source) {
		$files = [];
		foreach ($source as $s) {
			if (is_file($s)) {
				$files[] = $s;
			} elseif (is_dir($s)) {
				/** @noinspection SlowArrayOperationsInLoopInspection */
				$files = array_merge(
					$files,
					get_files_list($s, false, 'f', true, true, false, false, true)
				);
			}
		}
		return $files;
	}
	/**
	 * @param string   $component_root
	 * @param string[] $files Array, where new files will be appended
	 *
	 * @return bool
	 */
	protected function get_component_files ($component_root, &$files) {
		/**
		 * Do not allow building System module and CleverStyle theme
		 */
		if (in_array(basename($component_root), ['System', 'CleverStyle'])) {
			return false;
		}
		/**
		 * Components without meta.json also not allowed
		 */
		if (!file_exists("$component_root/meta.json")) {
			return false;
		}
		@unlink("$component_root/fs.json");
		$local_files = get_files_list($component_root, false, 'f', true, true, false, false, true);
		$files       = array_merge($files, $local_files);
		file_put_json(
			"$component_root/fs.json",
			array_values(
				_substr(
					$local_files,
					strlen("$component_root/")
				)
			)
		);
		$files[] = "$component_root/fs.json";
		return true;
	}
	/**
	 * @return string
	 */
	protected function get_readme () {
		return str_replace(
			[
				'$version$',
				'$image$'
			],
			[
				file_get_json("$this->root/components/modules/System/meta.json")['version'],
				h::img(
					[
						'src' => 'data:image/png;charset=utf-8;base64,'.base64_encode(file_get_contents("$this->root/install/logo.png"))
					]
				)
			],
			file_get_contents("$this->root/readme.html")
		);
	}
	/**
	 * @return string
	 */
	protected function get_htaccess () {
		return 'AddDefaultCharset utf-8
Options -Indexes -Multiviews +FollowSymLinks
IndexIgnore *.php *.pl *.cgi *.htaccess *.htpasswd

RewriteEngine On
RewriteBase /

<FilesMatch ".*/.*">
	Options -FollowSymLinks
</FilesMatch>
<FilesMatch "\.(css|js|gif|jpg|jpeg|png|ico|eot|ttc|ttf|svg|svgz|woff)$">
	RewriteEngine Off
</FilesMatch>
<Files license.txt>
	RewriteEngine Off
</Files>
#<Files Storage.php>
#	RewriteEngine Off
#</Files>

RewriteRule .* index.php
';
	}
	/**
	 * @param string      $module
	 * @param null|string $suffix
	 *
	 * @return string
	 */
	function module ($module, $suffix = null) {
		if ($module == 'System') {
			return "Can't build module, System module is a part of core, it is not necessary to build it as separate module";
		}
		return $this->generic_package_creation("$this->root/components/modules/$module", $suffix);
	}
	/**
	 * @param string      $plugin
	 * @param null|string $suffix
	 *
	 * @return string
	 */
	function plugin ($plugin, $suffix = null) {
		return $this->generic_package_creation("$this->root/components/plugins/$plugin", $suffix);
	}
	/**
	 * @param string      $theme
	 * @param null|string $suffix
	 *
	 * @return string
	 */
	function theme ($theme, $suffix = null) {
		if ($theme == 'CleverStyle') {
			return "Can't build theme, CleverStyle theme is a part of core, it is not necessary to build it as separate theme";
		}
		return $this->generic_package_creation("$this->root/themes/$theme", $suffix);
	}
	protected function generic_package_creation ($source_dir, $suffix = null) {
		if (!file_exists("$source_dir/meta.json")) {
			$component = basename($source_dir);
			return "Can't build $component, meta information (meta.json) not found";
		}
		$meta = file_get_json("$source_dir/meta.json");
		$type = '';
		$Type = '';
		switch ($meta['category']) {
			case 'modules':
				$type = 'module_';
				$Type = 'Module';
				break;
			case 'plugins':
				$type = 'plugins_';
				$Type = 'Plugin';
				break;
			case 'themes':
				$type = 'theme_';
				$Type = 'Theme';
				break;
		}
		$suffix      = $suffix ? "_$suffix" : '';
		$target_file = "$this->target/$type$meta[package]_$meta[version]$suffix.phar.php";
		if (file_exists($target_file)) {
			unlink($target_file);
		}
		$phar = new Phar($target_file);
		unset($target_file);
		$phar->startBuffering();
		@unlink("$source_dir/fs.json");
		$files  = get_files_list($source_dir, false, 'f', true, true, false, false, true);
		$length = strlen("$source_dir/");
		foreach ($files as $index => &$file) {
			$phar->addFile($file, "fs/$index");
			$file = substr($file, $length);
		}
		unset($index, $file, $length);
		/**
		 * Flip array to have direct access to files by name during extraction
		 */
		$phar->addFromString(
			'fs.json',
			_json_encode(
				array_flip($files)
			)
		);
		unset($files);
		$phar->addFile("$source_dir/meta.json", 'meta.json');
		$readme = false;
		if (file_exists("$source_dir/readme.html")) {
			$phar->addFile("$source_dir/readme.html", 'readme.html');
			$readme = 'readme.html';
		} elseif (file_exists("$source_dir/readme.txt")) {
			$phar->addFile("$source_dir/readme.txt", 'readme.txt');
			$readme = 'readme.txt';
		}
		if ($readme) {
			$phar->setStub("<?php Phar::webPhar(null, '$readme'); __HALT_COMPILER();");
		} else {
			$phar->addFromString('index.html', isset($meta['description']) ? $meta['description'] : $meta['package']);
			$phar->setStub("<?php Phar::webPhar(null, 'index.html'); __HALT_COMPILER();");
		}
		$phar->stopBuffering();
		return "Done! $Type $meta[package] $meta[version]";
	}
}
