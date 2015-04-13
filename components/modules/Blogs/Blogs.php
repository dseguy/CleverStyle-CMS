<?php
/**
 * @package        Blogs
 * @category       modules
 * @author         Nazar Mokrynskyi <nazar@mokrynskyi.com>
 * @copyright      Copyright (c) 2011-2015, Nazar Mokrynskyi
 * @license        MIT License, see license.txt
 */
namespace cs\modules\Blogs;
use
	cs\Event,
	cs\Cache\Prefix,
	cs\Config,
	cs\Language,
	cs\Text,
	cs\User,
	cs\DB\Accessor,
	cs\Singleton,
	cs\modules\Json_ld\Json_ld;

/**
 * @method static Blogs instance($check = false)
 */
class Blogs {
	use
		Accessor,
		Singleton;

	/**
	 * @var Prefix
	 */
	protected $cache;

	protected function construct () {
		$this->cache = new Prefix('Blogs');
	}
	/**
	 * Returns database index
	 *
	 * @return int
	 */
	protected function cdb () {
		return Config::instance()->module('Blogs')->db('posts');
	}
	/**
	 * Get data of specified post
	 *
	 * @param int|int[] $id
	 *
	 * @return array|false
	 */
	function get ($id) {
		if (is_array($id)) {
			foreach ($id as &$i) {
				$i = $this->get($i);
			}
			return $id;
		}
		$L        = Language::instance();
		$id       = (int)$id;
		$data     = $this->cache->get(
			"posts/$id/$L->clang",
			function () use ($id, $L) {
				$data = $this->db()->qf(
					[
						"SELECT
							`id`,
							`user`,
							`date`,
							`title`,
							`path`,
							`content`,
							`draft`
						FROM `[prefix]blogs_posts`
						WHERE
							`id` = '%s'
						LIMIT 1",
						$id
					]
				);
				if ($data) {
					$data['title']         = $this->ml_process($data['title']);
					$data['path']          = $this->ml_process($data['path']);
					$data['content']       = $this->ml_process($data['content']);
					$data['short_content'] = truncate(explode('<!-- pagebreak -->', $data['content'])[0]);
					$data['sections']      = $this->db()->qfas(
						"SELECT `section`
						FROM `[prefix]blogs_posts_sections`
						WHERE `id` = $id"
					);
					$data['tags']          = $this->get_tag(
						$this->db()->qfas(
							[
								"SELECT DISTINCT `tag`
								FROM `[prefix]blogs_posts_tags`
								WHERE
									`id`	= $id AND
									`lang`	= '%s'",
								$L->clang
							]
						) ?: []
					);
					if (!$data['tags']) {
						$l            = $this->db()->qfs(
							"SELECT `lang`
							FROM `[prefix]blogs_posts_tags`
							WHERE `id` = $id
							LIMIT 1"
						);
						$data['tags'] = $this->db()->qfas(
							"SELECT DISTINCT `tag`
							FROM `[prefix]blogs_posts_tags`
							WHERE
								`id`	= $id AND
								`lang`	= '$l'"
						);
						unset($l);
					}
				}
				return $data;
			}
		);
		$Comments = null;
		Event::instance()->fire(
			'Comments/instance',
			[
				'Comments' => &$Comments
			]
		);
		/**
		 * @var \cs\modules\Comments\Comments $Comments
		 */
		$data['comments_count'] = (int)(Config::instance()->module('Blogs')->enable_comments && $Comments ? $Comments->count($data['id']) : 0);
		return $data;
	}
	/**
	 * Get data of specified post
	 *
	 * @param int|int[] $id
	 *
	 * @return array|false
	 */
	function get_as_json_ld ($id) {
		$post = $this->get($id);;
		if (!$post) {
			return false;
		}
		$base_structure = [
			'@context' =>
				[
					'content'        => 'articleBody',
					'title'          => 'headline',
					'comments_count' => 'commentCount'
				] + Json_ld::context_stub(isset($post[0]) ? $post[0] : $post)
		];
		if (isset($post[0])) {
			$graph = [];
			foreach ($post as $p) {
				$graph[] = $this->get_as_json_ld_single_post($p);
			}
			return
				$base_structure +
				[
					'@graph' => $graph
				];
		}
		return
			$base_structure +
			$this->get_as_json_ld_single_post($post);
	}
	protected function get_as_json_ld_single_post ($post) {
		if (preg_match_all('/<img[^>]src=["\'](.*)["\']/Uims', $post['content'], $images)) {
			$images = $images[1];
		}
		$sections = [];
		if ($post['sections'] != [0]) {
			$sections = array_column(
				$this->get_section($post['sections']),
				'title'
			);
		}
		$L           = Language::instance();
		$base_url    = Config::instance()->base_url();
		$module_path = path(Language::instance()->Blogs);
		$url         = "$base_url/$module_path/$post[path]:$post[id]";
		return
			[
				'@id'            => $url,
				'@type'          => 'BlogPosting',
				'articleSection' => $sections,
				'author'         => Json_ld::Person($post['user']),
				'datePublished'  => Json_ld::Date($post['date']),
				'image'          => $images,
				'inLanguage'     => $L->clang,
				'keywords'       => $post['tags'],
				'url'            => $url
			] + $post;
	}
	/**
	 * Get latest posts
	 *
	 * @param int $page
	 * @param int $number
	 *
	 * @return int[]
	 */
	function get_latest_posts ($page, $number) {
		$number = (int)$number;
		$from   = ($page - 1) * $number;
		return $this->db()->qfas(
			"SELECT `id`
			FROM `[prefix]blogs_posts`
			WHERE `draft` = 0
			ORDER BY `date` DESC
			LIMIT $from, $number"
		) ?: [];
	}
	/**
	 * Get posts for section
	 *
	 * @param int $section
	 * @param int $page
	 * @param int $number
	 *
	 * @return int[]
	 */
	function get_for_section ($section, $page, $number) {
		$section = (int)$section;
		$number  = (int)$number;
		$from    = ($page - 1) * $number;
		return $this->db()->qfas(
			"SELECT `s`.`id`
			FROM `[prefix]blogs_posts_sections` AS `s`
				LEFT JOIN `[prefix]blogs_posts` AS `p`
			ON `s`.`id` = `p`.`id`
			WHERE
				`s`.`section`	= $section AND
				`p`.`draft`		= 0
			ORDER BY `p`.`date` DESC
			LIMIT $from, $number"
		) ?: [];
	}
	/**
	 * Get posts for tag
	 *
	 * @param int    $tag
	 * @param string $lang
	 * @param int    $page
	 * @param int    $number
	 *
	 * @return int[]
	 */
	function get_for_tag ($tag, $lang, $page, $number) {
		$number = (int)$number;
		$from   = ($page - 1) * $number;
		return $this->db()->qfas(
			[
				"SELECT `t`.`id`
				FROM `[prefix]blogs_posts_tags` AS `t`
					LEFT JOIN `[prefix]blogs_posts` AS `p`
				ON `t`.`id` = `p`.`id`
				WHERE
					`t`.`tag`	= '%s' AND
					`p`.`draft`	= 0 AND
					`t`.`lang`	= '%s'
				ORDER BY `p`.`date` DESC
				LIMIT $from, $number",
				$tag,
				$lang
			]
		) ?: [];
	}
	/**
	 * Get count of posts for tag
	 *
	 * @param int    $tag
	 * @param string $lang
	 * @param int    $page
	 * @param int    $number
	 *
	 * @return int
	 */
	function get_for_tag_count ($tag, $lang, $page, $number) {
		$number = (int)$number;
		$from   = ($page - 1) * $number;
		return $this->db()->qfs(
			[
				"SELECT COUNT(`t`.`id`)
				FROM `[prefix]blogs_posts_tags` AS `t`
					LEFT JOIN `[prefix]blogs_posts` AS `p`
				ON `t`.`id` = `p`.`id`
				WHERE
					`t`.`tag`	= '%s' AND
					`p`.`draft`	= 0 AND
					`t`.`lang`	= '%s'
				ORDER BY `p`.`date` DESC
				LIMIT $from, $number",
				$tag,
				$lang
			]
		) ?: 0;
	}
	/**
	 * Add new post
	 *
	 * @param string   $title
	 * @param string   $path
	 * @param string   $content
	 * @param int[]    $sections
	 * @param string[] $tags
	 * @param bool     $draft
	 *
	 * @return false|int Id of created post on success of <b>false</> on failure
	 */
	function add ($title, $path, $content, $sections, $tags, $draft) {
		if (empty($tags) || empty($content)) {
			return false;
		}
		$sections = array_intersect(
			array_keys($this->get_sections_list()),
			$sections
		);
		if (empty($sections) || count($sections) > Config::instance()->module('Blogs')->max_sections) {
			return false;
		}
		if ($this->db_prime()->q(
			"INSERT INTO `[prefix]blogs_posts`
				(
					`user`,
					`date`,
					`draft`
				)
			VALUES
				(
					'%s',
					'%s',
					'%s'
				)",
			User::instance()->id,
			$draft ? 0 : time(),
			(int)(bool)$draft
		)
		) {
			$id = $this->db_prime()->id();
			if ($this->set_internal($id, $title, $path, $content, $sections, $tags, $draft, true)) {
				return $id;
			} else {
				$this->db_prime()->q(
					"DELETE FROM `[prefix]blogs_posts`
					WHERE `id` = $id
					LIMIT 1"
				);
				$this->db_prime()->q(
					"DELETE FROM `[prefix]blogs_posts_sections`
					WHERE `id` = $id"
				);
				$this->db_prime()->q(
					"DELETE FROM `[prefix]blogs_posts_tags`
					WHERE `id` = $id"
				);
			}
		}
		return false;
	}
	/**
	 * Set data of specified post
	 *
	 * @param int      $id
	 * @param string   $title
	 * @param string   $path
	 * @param string   $content
	 * @param int[]    $sections
	 * @param string[] $tags
	 * @param bool     $draft
	 *
	 * @return bool
	 */
	function set ($id, $title, $path, $content, $sections, $tags, $draft) {
		return $this->set_internal($id, $title, $path, $content, $sections, $tags, $draft);
	}
	/**
	 * Set data of specified post
	 *
	 * @param int      $id
	 * @param string   $title
	 * @param string   $path
	 * @param string   $content
	 * @param int[]    $sections
	 * @param string[] $tags
	 * @param bool     $draft
	 * @param bool     $add
	 *
	 * @return bool
	 */
	function set_internal ($id, $title, $path, $content, $sections, $tags, $draft, $add = false) {
		if (empty($tags) || empty($content)) {
			return false;
		}
		$Config      = Config::instance();
		$L           = Language::instance();
		$id          = (int)$id;
		$path        = path(trim($path ?: $title));
		$title       = xap(trim($title));
		$module_data = $Config->module('Blogs');
		$content     = xap($content, true, $module_data->allow_iframes_without_content);
		$sections    = array_intersect(
			array_keys($this->get_sections_list()),
			$sections
		);
		if (empty($sections) || count($sections) > $module_data->max_sections) {
			return false;
		}
		$sections = implode(
			',',
			array_unique(
				array_map(
					function ($section) use ($id) {
						return "($id, $section)";
					},
					$sections
				)
			)
		);
		$tags     = array_unique($tags);
		$tags     = implode(
			',',
			array_unique(
				array_map(
					function ($tag) use ($id, $L) {
						return "($id, $tag, '$L->clang')";
					},
					$this->process_tags($tags)
				)
			)
		);
		$data     = $this->get($id);
		if (!$this->db_prime()->q(
			[
				"DELETE FROM `[prefix]blogs_posts_sections`
				WHERE `id` = '%5\$s'",
				"INSERT INTO `[prefix]blogs_posts_sections`
					(`id`, `section`)
				VALUES
					$sections",
				"UPDATE `[prefix]blogs_posts`
				SET
					`title`		= '%s',
					`path`		= '%s',
					`content`	= '%s',
					`draft`		= '%s'
				WHERE `id` = '%s'
				LIMIT 1",
				"DELETE FROM `[prefix]blogs_posts_tags`
				WHERE
					`id`	= '%5\$s' AND
					`lang`	= '$L->clang'",
				"INSERT INTO `[prefix]blogs_posts_tags`
					(`id`, `tag`, `lang`)
				VALUES
					$tags"
			],
			$this->ml_set('Blogs/posts/title', $id, $title),
			$this->ml_set('Blogs/posts/path', $id, $path),
			$this->ml_set('Blogs/posts/content', $id, $content),
			(int)(bool)$draft,
			$id
		)
		) {
			return false;
		}
		if ($add && $Config->core['multilingual']) {
			foreach ($Config->core['active_languages'] as $lang) {
				if ($lang != $L->clanguage) {
					$lang = $L->get('clang', $lang);
					$this->db_prime()->q(
						"INSERT INTO `[prefix]blogs_posts_tags`
							(`id`, `tag`, `lang`)
						SELECT `id`, `tag`, '$lang'
						FROM `[prefix]blogs_posts_tags`
						WHERE
							`id`	= $id AND
							`lang`	= '$L->clang'"
					);
				}
			}
			unset($lang);
		}
		preg_match_all('/"(http[s]?:\/\/.*)"/Uims', $data['content'], $old_files);
		preg_match_all('/"(http[s]?:\/\/.*)"/Uims', $content, $new_files);
		$old_files = isset($old_files[1]) ? $old_files[1] : [];
		$new_files = isset($new_files[1]) ? $new_files[1] : [];
		if ($old_files || $new_files) {
			foreach (array_diff($old_files, $new_files) as $file) {
				Event::instance()->fire(
					'System/upload_files/del_tag',
					[
						'tag' => "Blogs/posts/$id/$L->clang",
						'url' => $file
					]
				);
			}
			unset($file);
			foreach (array_diff($new_files, $old_files) as $file) {
				Event::instance()->fire(
					'System/upload_files/add_tag',
					[
						'tag' => "Blogs/posts/$id/$L->clang",
						'url' => $file
					]
				);
			}
			unset($file);
		}
		unset($old_files, $new_files);
		if ($data['draft'] == 1 && !$draft && $data['date'] == 0) {
			$this->db_prime()->q(
				"UPDATE `[prefix]blogs_posts`
				SET `date` = '%s'
				WHERE `id` = '%s'
				LIMIT 1",
				time(),
				$id
			);
		}
		$Cache = $this->cache;
		unset(
			$Cache->{"posts/$id"},
			$Cache->sections,
			$Cache->total_count
		);
		return true;
	}
	/**
	 * Delete specified post
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	function del ($id) {
		$id = (int)$id;
		if (!$this->db_prime()->q(
			[
				"DELETE FROM `[prefix]blogs_posts`
				WHERE `id` = $id
				LIMIT 1",
				"DELETE FROM `[prefix]blogs_posts_sections`
				WHERE `id` = $id",
				"DELETE FROM `[prefix]blogs_posts_tags`
				WHERE `id` = $id"
			]
		)
		) {
			return false;
		}
		$this->ml_del('Blogs/posts/title', $id);
		$this->ml_del('Blogs/posts/path', $id);
		$this->ml_del('Blogs/posts/content', $id);
		Event::instance()->fire(
			'System/upload_files/del_tag',
			[
				'tag' => "Blogs/posts/$id%"
			]
		);
		$Comments = null;
		Event::instance()->fire(
			'Comments/instance',
			[
				'Comments' => &$Comments
			]
		);
		/**
		 * @var \cs\modules\Comments\Comments $Comments
		 */
		if ($Comments) {
			$Comments->del_all($id);
		}
		$Cache = $this->cache;
		unset(
			$Cache->{"posts/$id"},
			$Cache->sections,
			$Cache->total_count
		);
		return true;
	}
	/**
	 * Get total count of posts
	 *
	 * @return int
	 */
	function get_total_count () {
		return $this->cache->get(
			'total_count',
			function () {
				return $this->db()->qfs(
					"SELECT COUNT(`id`)
					FROM `[prefix]blogs_posts`
					WHERE `draft` = 0"
				);
			}
		);
	}
	/**
	 * Get array of sections in form [<i>id</i> => <i>title</i>]
	 *
	 * @return array|false
	 */
	function get_sections_list () {
		$L = Language::instance();
		return $this->cache->get(
			"sections/list/$L->clang",
			function () {
				return $this->get_sections_list_internal(
					$this->get_sections_structure()
				);
			}
		);
	}
	private function get_sections_list_internal ($structure) {
		if (!empty($structure['sections'])) {
			$list = [];
			foreach ($structure['sections'] as $section) {
				$list += $this->get_sections_list_internal($section);
			}
			return $list;
		} else {
			return [$structure['id'] => $structure['title']];
		}
	}
	/**
	 * Get array of sections structure
	 *
	 * @return array|false
	 */
	function get_sections_structure () {
		$L = Language::instance();
		return $this->cache->get(
			"sections/structure/$L->clang",
			function () {
				return $this->get_sections_structure_internal();
			}
		);
	}
	private function get_sections_structure_internal ($parent = 0) {
		$structure = [
			'id'    => $parent,
			'posts' => 0
		];
		if ($parent != 0) {
			$structure = array_merge(
				$structure,
				$this->get_section($parent)
			);
		} else {
			$structure['title'] = Language::instance()->root_section;
			$structure['posts'] = $this->db()->qfs(
				[
					"SELECT COUNT(`s`.`id`)
					FROM `[prefix]blogs_posts_sections` AS `s`
						LEFT JOIN `[prefix]blogs_posts` AS `p`
					ON `s`.`id` = `p`.`id`
					WHERE
						`s`.`section`	= '%s' AND
						`p`.`draft`		= 0",
					$structure['id']
				]
			);
		}
		$sections              = $this->db()->qfa(
			[
				"SELECT
					`id`,
					`path`
				FROM `[prefix]blogs_sections`
				WHERE `parent` = '%s'",
				$parent
			]
		);
		$structure['sections'] = [];
		if (!empty($sections)) {
			foreach ($sections as $section) {
				$structure['sections'][$this->ml_process($section['path'])] = $this->get_sections_structure_internal($section['id']);
			}
		}
		return $structure;
	}
	/**
	 * Get data of specified section
	 *
	 * @param int|int[] $id
	 *
	 * @return array|false
	 */
	function get_section ($id) {
		if (is_array($id)) {
			foreach ($id as &$i) {
				$i = $this->get_section($i);
			}
			return $id;
		}
		$L  = Language::instance();
		$id = (int)$id;
		return $this->cache->get(
			"sections/$id/$L->clang",
			function () use ($id) {
				$data              = $this->db()->qf(
					[
						"SELECT
							`id`,
							`title`,
							`path`,
							`parent`,
							(
								SELECT COUNT(`s`.`id`)
								FROM `[prefix]blogs_posts_sections` AS `s`
									LEFT JOIN `[prefix]blogs_posts` AS `p`
								ON `s`.`id` = `p`.`id`
								WHERE
									`s`.`section`	= '%1\$s' AND
									`p`.`draft`		= 0
							) AS `posts`
						FROM `[prefix]blogs_sections`
						WHERE `id` = '%1\$s'
						LIMIT 1",
						$id
					]
				);
				$data['title']     = $this->ml_process($data['title']);
				$data['path']      = $this->ml_process($data['path']);
				$data['full_path'] = [$data['path']];
				$parent            = $data['parent'];
				while ($parent != 0) {
					$section             = $this->get_section($parent);
					$data['full_path'][] = $section['path'];
					$parent              = $section['parent'];
				}
				$data['full_path'] = implode('/', array_reverse($data['full_path']));
				return $data;
			}
		);
	}
	/**
	 * Add new section
	 *
	 * @param int    $parent
	 * @param string $title
	 * @param string $path
	 *
	 * @return false|int Id of created section on success of <b>false</> on failure
	 */
	function add_section ($parent, $title, $path) {
		$parent = (int)$parent;
		$posts  = $this->db_prime()->qfa(
			"SELECT `id`
			FROM `[prefix]blogs_posts_sections`
			WHERE `section` = $parent"
		);
		if ($this->db_prime()->q(
			"INSERT INTO `[prefix]blogs_sections`
				(`parent`)
			VALUES
				($parent)"
		)
		) {
			$Cache = $this->cache;
			$id    = $this->db_prime()->id();
			if ($posts) {
				$this->db_prime()->q(
					"UPDATE `[prefix]blogs_posts_sections`
					SET `section` = $id
					WHERE `section` = $parent"
				);
				foreach ($posts as $post) {
					unset($Cache->{"posts/$post[id]"});
				}
				unset($post);
			}
			unset($posts);
			$this->set_section($id, $parent, $title, $path);
			unset(
				$Cache->{'sections/list'},
				$Cache->{'sections/structure'}
			);
			return $id;
		}
		return false;
	}
	/**
	 * Set data of specified section
	 *
	 * @param int    $id
	 * @param int    $parent
	 * @param string $title
	 * @param string $path
	 *
	 * @return bool
	 */
	function set_section ($id, $parent, $title, $path) {
		$parent = (int)$parent;
		$path   = path($path ?: $title);
		$title  = xap(trim($title));
		$id     = (int)$id;
		if ($this->db_prime()->q(
			"UPDATE `[prefix]blogs_sections`
			SET
				`parent`	= '%s',
				`title`		= '%s',
				`path`		= '%s'
			WHERE `id` = '%s'
			LIMIT 1",
			$parent,
			$this->ml_set('Blogs/sections/title', $id, $title),
			$this->ml_set('Blogs/sections/path', $id, $path),
			$id
		)
		) {
			unset($this->cache->sections);
			return true;
		}
		return false;
	}
	/**
	 * Delete specified section
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	function del_section ($id) {
		$id                = (int)$id;
		$parent_section    = $this->db_prime()->qfs(
			[
				"SELECT `parent`
				FROM `[prefix]blogs_sections`
				WHERE `id` = '%s'
				LIMIT 1",
				$id
			]
		);
		$new_posts_section = $this->db_prime()->qfs(
			[
				"SELECT `id`
				FROM `[prefix]blogs_sections`
				WHERE
					`parent` = '%s' AND
					`id` != '%s'
				LIMIT 1",
				$parent_section,
				$id
			]
		);
		if ($this->db_prime()->q(
			[
				"UPDATE `[prefix]blogs_sections`
				SET `parent` = '%2\$s'
				WHERE `parent` = '%1\$s'",
				"UPDATE IGNORE `[prefix]blogs_posts_sections`
				SET `section` = '%3\$s'
				WHERE `section` = '%1\$s'",
				"DELETE FROM `[prefix]blogs_posts_sections`
				WHERE `section` = '%1\$s'",
				"DELETE FROM `[prefix]blogs_sections`
				WHERE `id` = '%1\$s'
				LIMIT 1"
			],
			$id,
			$parent_section,
			$new_posts_section ?: $parent_section
		)
		) {
			$this->ml_del('Blogs/sections/title', $id);
			$this->ml_del('Blogs/sections/path', $id);
			unset($this->cache->{'/'});
			return true;
		} else {
			return false;
		}
	}
	private function ml_process ($text) {
		return Text::instance()->process($this->cdb(), $text, true);
	}
	private function ml_set ($group, $label, $text) {
		return Text::instance()->set($this->cdb(), $group, $label, $text);
	}
	private function ml_del ($group, $label) {
		return Text::instance()->del($this->cdb(), $group, $label);
	}
	/**
	 * Get tag text
	 *
	 * @param int|int[] $id
	 *
	 * @return string|string[]
	 */
	function get_tag ($id) {
		if (is_array($id)) {
			foreach ($id as &$i) {
				$i = $this->get_tag($i);
			}
			return $id;
		}
		$id = (int)$id;
		return $this->cache->get(
			"tags/$id",
			function () use ($id) {
				return $this->db()->qfs(
					[
						"SELECT `text`
						FROM `[prefix]blogs_tags`
						WHERE `id` = '%s'
						LIMIT 1",
						$id
					]
				);
			}
		);
	}
	/**
	 * Find tag by its text
	 *
	 * @param string $tag_text
	 *
	 * @return false|int
	 */
	function find_tag ($tag_text) {
		return $this->db()->qfs(
			[
				"SELECT `id`
				FROM  `[prefix]blogs_tags`
				WHERE `text` = '%s'
				LIMIT 1",
				trim(xap($tag_text))
			]
		);
	}
	/**
	 * Accepts array of string tags and returns corresponding array of id's of these tags, new tags will be added automatically
	 *
	 * @param string[] $tags
	 *
	 * @return int[]
	 */
	private function process_tags ($tags) {
		if (!$tags) {
			return [];
		}
		$tags = xap($tags);
		$cdb  = $this->db_prime();
		$cdb->insert(
			"INSERT IGNORE INTO `[prefix]blogs_tags`
				(`text`)
			VALUES
				('%s')",
			array_map(
				function ($tag) {
					return [$tag];
				},
				$tags
			),
			true
		);
		$in = [];
		foreach ($tags as $tag) {
			$in[] = $cdb->s($tag);
		}
		$in = implode(',', $in);
		return $cdb->qfas(
			"SELECT `id`
			FROM `[prefix]blogs_tags`
			WHERE `text` IN($in)"
		);
	}
}
