<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Utils;
use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Filesystem\Folder;
use Grav\Common\Filesystem\RecursiveFolderFilterIterator;
use Grav\Common\Page\Page;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Yaml\Yaml;
use Twig_SimpleFunction;
require_once PLUGINS_DIR .  'comments/class/Comment.php';

class CommentsPlugin extends Plugin
{
    protected $route = 'comments';
    protected $enable = false;
    protected $comments_cache_id;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    /**
     * Add the comment form information to the page header dynamically
     *
     * Used by Form plugin >= 2.0
     */
    public function onFormPageHeaderProcessed(Event $event)
    {
        $header = $event['header'];

        if ($this->enable) {
            if (!isset($header->form)) {
                $header->form = $this->grav['config']->get('plugins.comments.form');
            }
        }

        $event->header = $header;
    }

    public function onTwigSiteVariables() {
        $this->grav['twig']->enable_comments_plugin = $this->enable;
        $this->grav['twig']->comments = $this->fetchComments();
        //$this->grav['twig']->recent_comments = $this->getRecentComments(); //cannot be used for functions with arguments
        $function = new Twig_SimpleFunction('recent_comments', [$this, 'getRecentComments']);
        $this->grav['twig']->twig()->addFunction($function);
        
        if ($this->config->get('plugins.comments.built_in_css')) {
            $this->grav['assets']
                ->addCss('plugin://comments/assets/comments.css');
        }
        $this->grav['assets']
            ->add('jquery', 101)
            ->addJs('plugin://comments/assets/comments.js');
    }

    /**
     * Determine if the plugin should be enabled based on the enable_on_routes and disable_on_routes config options
     */
    private function calculateEnable() {
        $uri = $this->grav['uri'];

        $disable_on_routes = (array) $this->config->get('plugins.comments.disable_on_routes');
        $enable_on_routes = (array) $this->config->get('plugins.comments.enable_on_routes');
        $callback = $this->config->get('plugins.comments.ajax_callback');

        $path = $uri->path();

        if ($callback === $path) {
			$this->enable = true;
			return;
		}
		
        if (!in_array($path, $disable_on_routes)) {
            if (in_array($path, $enable_on_routes)) {
                $this->enable = true;
            } else {
                foreach($enable_on_routes as $route) {
                    if (Utils::startsWith($path, $route)) {
                        $this->enable = true;
                        break;
                    }
                }
            }
        }
    }

    /**
     * Frontend side initialization
     */
    private function initializeFrontend()
    {
        $this->calculateEnable();

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
        ]);

        if ($this->enable) {
            $this->enable([
                'onPageInitialized' => ['onPageInitialized', 0],
                'onFormProcessed' => ['onFormProcessed', 0],
                'onFormPageHeaderProcessed' => ['onFormPageHeaderProcessed', 0],
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
            ]);
        }

        $cache = $this->grav['cache'];
        $uri = $this->grav['uri'];

        //init cache id
        $this->comments_cache_id = md5('comments-data' . $cache->getKey() . '-' . $uri->url());
    }

    /**
     * Admin side initialization
     */
    private function initializeAdmin()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        $this->enable([
            'onTwigTemplatePaths' => ['onTwigAdminTemplatePaths', 0],
            'onAdminMenu' => ['onAdminMenu', 0],
            'onAdminTaskExecute' => ['onAdminTaskExecute', 0],
            'onAdminAfterSave' => ['onAdminAfterSave', 0],
            'onAdminAfterDelete' => ['onAdminAfterDelete', 0],
            'onDataTypeExcludeFromDataManagerPluginHook' => ['onDataTypeExcludeFromDataManagerPluginHook', 0],
        ]);

        if (strpos($uri->path(), $this->config->get('plugins.admin.route') . '/' . $this->route) === false) {
            return;
        }

        $page = $this->grav['uri']->param('page');
        $comments = $this->getLastComments($page);

        if ($page > 0) {
            echo json_encode($comments);
            exit();
        }

        $this->grav['twig']->comments = $comments;
        $this->grav['twig']->pages = $this->fetchPages();
    }

    /**
     */
    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            $this->initializeAdmin();
        } else {
            $this->initializeFrontend();
        }
    }

    /**
     * Handle ajax call.
     */
    public function onPageInitialized()
    {
        $is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
        //$callback = $this->config->get('plugins.comments.ajax_callback');
        // Process comment if required
        if ($is_ajax) {// || $callback === $this->grav['uri']->path()
			$action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
			switch ($action) {
				case 'addComment':
				case '':
				case null:
					// try to add the comment
					$result = $this->addCommentAjax(true);
					echo json_encode([
						'status' => $result[0],
						'message' => $result[1],
						'data' => $result[2],
					]);
					break;
				case 'delete':
					// try to delete the comment
					$result = $this->deleteComment(true);
					echo json_encode([
						'status' => $result[0],
						'message' => $result[1],
						'data' => $result[2],
					]);
					break;
				default:
					//request unknown, present error page
					//Set a 400 (bad request) response code.
					http_response_code(400);
					echo 'request malformed - action unknown';
					break;
			}
            exit(); //prevents the page frontend from beeing displayed.
        }
    }

    /**
     * Validate ajax input before deleting comment
     * 
     * @return boolean[]|string[]|array[][]
     */
    private function deleteComment()
    {
        $language = $this->grav['language'];
        if (!$this->grav['user']->authorize('admin.super')) {
			http_response_code(403);
            return [false, 'access forbidden', [0, 0]];
        }
		$id		= filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
		$nonce	= filter_input(INPUT_POST, 'nonce', FILTER_SANITIZE_STRING);
        // ensure both values are sent
        if (is_null($id) || is_null($nonce)) {
            // Set a 400 (bad request) response code and exit.
            http_response_code(400);
            return [false, 'request malformed - missing parameter(s)', [0, 0]];
        }
        if (!Utils::verifyNonce($nonce, 'comments')) {
			http_response_code(403);
            return [false, 'Invalid security nonce', [0, $nonce]];
        }
		$lang = $this->grav['language']->getLanguage();
		$path = $this->grav['page']->path();
		$route = $this->grav['page']->route();
        $data = $this->removeComment($route, $path, $id, $lang);
        if ($data[0]) {
			return [true, $language->translate('PLUGIN_COMMENTS.DELETE_SUCCESS'), $data[1]];
		} else {
			http_response_code(403); //forbidden
			return [false, $language->translate('PLUGIN_COMMENTS.DELETE_FAIL'), $data[1]];
		}
	}

    /**
     * Validate ajax input before adding comment
     * 
     * @return boolean[]|string[]|array[][]
     */
    private function addCommentAjax()
    {
        $language = $this->grav['language'];
        if (!$_SERVER["REQUEST_METHOD"] == "POST") {
			// Not a POST request, set a 403 (forbidden) response code.
			http_response_code(403);
			return [false, 'There was a problem with your submission, please try again.', [0, 0]];
		}
        // get and filter the data
        if (!isset($_POST['data']) || !is_array($_POST['data'])) {
            // Set a 400 (bad request) response code and exit.
            http_response_code(400);
            return [false, 'missing data', [0, 0]];
        }
		$input = array();
		$input['parent_id']		= filter_input(INPUT_POST, 'parentID', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
		$input['name']			= isset($_POST['data']['name']) ? filter_var($_POST['data']['name'], FILTER_SANITIZE_STRING) : null;
		$input['email']			= isset($_POST['data']['email']) ? filter_var($_POST['data']['email'], FILTER_SANITIZE_EMAIL) : null;
		$input['text']			= isset($_POST['data']['text']) ? filter_var($_POST['data']['text'], FILTER_SANITIZE_STRING) : null;
		$input['date']			= isset($_POST['data']['date']) ? filter_var($_POST['data']['date'], FILTER_SANITIZE_STRING) : null;
		$input['title']			= isset($_POST['data']['title']) ? filter_var($_POST['data']['title'], FILTER_SANITIZE_STRING) : null;
		$input['lang']			= isset($_POST['data']['lang']) ? filter_var($_POST['data']['lang'], FILTER_SANITIZE_STRING) : null;
		$input['path']			= isset($_POST['data']['path']) ? filter_var($_POST['data']['path'], FILTER_SANITIZE_STRING) : null;
		$input['form-name']		= filter_input(INPUT_POST, 'form-name', FILTER_SANITIZE_STRING);
		$input['form-nonce']	= filter_input(INPUT_POST, 'form-nonce', FILTER_SANITIZE_STRING);
        if (!Utils::verifyNonce($input['form-nonce'], 'comments')) {
			http_response_code(403);
            return [false, 'Invalid security nonce', [0, $input['form-nonce']]];
        }
        // ensure both values are sent
        if (is_null($input['title']) || is_null($input['text'])) {
            // Set a 400 (bad request) response code and exit.
            http_response_code(400);
            return [false, 'missing either text or title', [0, 0]];
			//return [false, $language->translate('PLUGIN_COMMENTS.FAIL'), $data];
        }
        // sanity checks for parents
        if ($input['parent_id'] < 0) {
            $input['parent_id'] = 0;
        } elseif ($input['parent_id'] > 999 ) { //TODO: Change to 'exists in list of comment ids
            $input['parent_id'] = 0;
        }
		$lang = $this->grav['language']->getLanguage();
		$path = $this->grav['page']->path();
		$route = $this->grav['page']->route();
		$user = $this->grav['user']->authenticated ? $this->grav['user']->username : '';
		$isAdmin = $this->grav['user']->authorize('admin.login');
        $comment = $this->saveComment($route, $path, $input['parent_id'], $lang, $input['text'], $input['name'], $input['email'], $input['title'], $user, $isAdmin);
        //$comments = $this->fetchComments();
		$data = array(
			'parent_id' => $comment['parent'],
			'id' => $comment['id'],
			'text' => $comment['text'],
			'title' => $comment['title'],
			'name' => $comment['author'],
			'date' => $comment['date'],
			'hash' => md5(strtolower(trim($comment['email']))),
			'authenticated' => !empty($comment['user']),
			'isAdmin' => !empty($comment['isAdmin']),
			'ADD_REPLY' => $language->translate('PLUGIN_COMMENTS.ADD_REPLY'),
			'REPLY' => $language->translate('PLUGIN_COMMENTS.REPLY'),
			'WRITTEN_ON' => $language->translate('PLUGIN_COMMENTS.WRITTEN_ON'),
			'BY' => $language->translate('PLUGIN_COMMENTS.BY'),
		);
        return [true, $language->translate('PLUGIN_COMMENTS.SUCCESS'), $data];
    }

    /**
     * Handle form processing instructions.
     *
     * @param Event $event
     */
    private function removeComment($route, $path, $id, $lang)
    {
				$entry_removed = false;
				$message = '';
				$date = time();//date('D, d M Y H:i:s', time());
				
				/******************************/
				/** store comments with page **/
				/******************************/
				$localfilename = $path . '/comments.yaml';
				$localfile = CompiledYamlFile::instance($localfilename);
                if (file_exists($localfilename)) {
                    $data = $localfile->content();
					if(isset($data['comments']) && is_array($data['comments'])) {
						foreach($data['comments'] as $key => $comment) {
							if(!empty($comment['parent_id']) && $comment['parent_id'] == $id) {
								//hit an existing comment that is a reply to comment selected for deletion.
								//deletion of "parent" comment not allowed to preserve integrity of nested comments.
								//TODO: Alternatively allow it to mark parent comments as deleted
								//      and make sure (via Comment class / setCommentLevels) that children are
								//      filtered out from fetch regardless of their own deletion state.
								$data['comments'][$key] = array_merge(array('deleted' => ''), $comment);
								//set date after merge
								//reason: could be possible that "deleted" already exists (e.g. false or '') in $comment which would overwrite the first (newly added) occurence
								$data['comments'][$key]['deleted'] = $date;
								//no need to look further as ids are supposed to be unique.
								$localfile->save($data);
								$entry_removed = false;
								$reply_id = empty($comment['id']) ? '' : $comment['id'];
								$message = "Found active reply ($reply_id) for selected comment ($id).";
								return [$entry_removed, $message];
								break;
							}
						}
						foreach($data['comments'] as $key => $comment) {
							if(!empty($comment['id']) && $comment['id'] == $id) {
								//add deleted as first item in array (better readability in file)
								$data['comments'][$key] = array_merge(array('deleted' => ''), $comment);
								//set date after merge
								//reason: could be possible that "deleted" already exists (e.g. false or '') in $comment which would overwrite the first (newly added) occurence
								$data['comments'][$key]['deleted'] = $date;
								//no need to look further as ids are supposed to be unique.
								$localfile->save($data);
								$entry_removed = true;
								$message = "Deleted comment ($id) via path ($path)";
								break;
							}
						}
					}
                } else {
					//nothing
                }
				/**********************************/
				/** store comments in index file **/
				/**********************************/
				$indexfilename = DATA_DIR . 'comments/index.yaml';
				$indexfile = CompiledYamlFile::instance($indexfilename);
                if (file_exists($indexfilename)) {
                    $dataIndex = $indexfile->content();
					if(isset($dataIndex['comments']) && is_array($dataIndex['comments'])) {
						foreach($dataIndex['comments'] as $key => $comment) {
							if(!empty($comment['page']) && !empty($comment['id']) && $comment['page'] == $route && $comment['id'] == $id) {
								//add deleted as first item in array (better readability in file)
								$dataIndex['comments'][$key] = array_merge(array('deleted' => ''), $comment);
								//set date after merge
								//reason: could be possible that "deleted" already exists (e.g. false or '') in $comment which would overwrite the first (newly added) occurence
								$dataIndex['comments'][$key]['deleted'] = $date;
								//no need to look further as ids are supposed to be unique.
								$indexfile->save($dataIndex);
								break;
							}
						}
					}
                } else {
					//nothing
                }
                //clear cache
                $this->grav['cache']->delete($this->comments_cache_id);
				
				return [$entry_removed, $message];
    }

    /**
     * Handle form processing instructions.
     *
     * @param Event $event
     */
    private function saveComment($route, $path, $parent_id, $lang, $text, $name, $email, $title, $user = "", $isAdmin = false)
    {
				$date = date('D, d M Y H:i:s', time());
				
				/******************************/
				/** store comments with page **/
				/******************************/
				$localfilename = $path . '/comments.yaml';
				$localfile = CompiledYamlFile::instance($localfilename);
                if (file_exists($localfilename)) {
                    $data = $localfile->content();
					$data['autoincrement']++;
                } else {
                    $data = array(
                        'autoincrement' => 1,
                        'comments' => array()
                    );
                }
				$localid = $data['autoincrement'];
				$newComment = [
					'id' => $data['autoincrement'],
					'parent' => $parent_id,
					'lang' => $lang,
					'title' => $title,
					'text' => $text,
					'date' => $date,
					'author' => $name,
					'email' => $email,
					'user' => $user,
					'isAdmin' => !empty($isAdmin),
				];
				$data['comments'][] = $newComment;
                $localfile->save($data);
				/**********************************/
				/** store comments in index file **/
				/**********************************/
				$indexfilename = DATA_DIR . 'comments/index.yaml';
				$indexfile = CompiledYamlFile::instance($indexfilename);
                if (file_exists($indexfilename)) {
                    $dataIndex = $indexfile->content();
                } else {
                    $dataIndex = array(
                        'comments' => array()
                    );
                }
				$dataIndex['comments'][] = [
					'page' => $route,
					'id' => $localid,
					'parent' => $parent_id,
					'lang' => $lang,
					'title' => $title,
					'text' => $text,
					'date' => $date,
					'author' => $name,
					'email' => $email
				];
                $indexfile->save($dataIndex);


                //clear cache
                $this->grav['cache']->delete($this->comments_cache_id);
				
				return $newComment;
    }

    /**
     * Handle form processing instructions.
     *
     * @param Event $event
     */
    public function onFormProcessed(Event $event)
    {
        $form = $event['form'];
        $action = $event['action'];
        $params = $event['params'];

        if (!$this->active) {
            return;
        }

        switch ($action) {
            case 'addComment':
                $post = isset($_POST['data']) ? $_POST['data'] : [];

                $lang = filter_var(urldecode($post['lang']), FILTER_SANITIZE_STRING);
                $path = filter_var(urldecode($post['path']), FILTER_SANITIZE_STRING);
                $text = filter_var(urldecode($post['text']), FILTER_SANITIZE_STRING);
                $name = filter_var(urldecode($post['name']), FILTER_SANITIZE_STRING);
                $email = filter_var(urldecode($post['email']), FILTER_SANITIZE_STRING);
                $title = filter_var(urldecode($post['title']), FILTER_SANITIZE_STRING);
				$parent_id = 0;

				$username = '';
				$isAdmin = false;
                if (isset($this->grav['user'])) {
                    $user = $this->grav['user'];
                    if ($user->authenticated) {
                        $name = $user->fullname;
                        $email = $user->email;
                    }
					$username = $this->grav['user']->authenticated ? $this->grav['user']->username : '';
					$isAdmin = $this->grav['user']->authorize('admin.login');
                }

                /** @var Language $language */
                $lang = $this->grav['language']->getLanguage();
				
				$path = $this->grav['page']->path();
				$route = $this->grav['page']->route();

                $this->saveComment($route, $path, $parent_id, $lang, $text, $name, $email, $title, $username, $isAdmin);

                break;
        }
    }

    /**
     * Used to add a recent comments widget. Call {{ recent_comments(123,12) }} specifying an integer representing the result length.
     *
     * Returns three different arrays with stats and comments.
     *
     * @param integer $limit         max amount of comments in result set
     * @param integer $limit_pages   max amount of pages in result set
     *
     * @return array|array|array global stats, page stats, list of recent comments, options
     */
    public function getRecentComments($limit, $limit_pages)
    {
        $routes = $this->grav['pages']->routes(); //routes[route] => path
        $paths = array_flip($routes);
        $cache = $this->grav['cache'];
        $options = array(
            'comments_limit' => $limit,
            'pages_limit' => $limit_pages,
        );
        //use cached stats if possible
        $recent_comments_cache_id = md5('comments-stats' . $cache->getKey());
        if ($recent_comments = $cache->fetch($recent_comments_cache_id)) {
            //use cache only if limits are big enough
            if($recent_comments['options']['comments_limit'] >= $options['comments_limit'] && $recent_comments['options']['pages_limit'] >= $options['pages_limit']) {
                return $recent_comments;
            }
        }
        
        $path = PAGES_DIR;
        $dirItr     = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $itrFilter = new \RecursiveIteratorIterator($dirItr, \RecursiveIteratorIterator::SELF_FIRST);
        $filesItr = new \RegexIterator($itrFilter, '/^.+comments\.yaml$/i');
        $files = array();
        $global_stats = array(
            'active_entries' => 0,
            'deleted_entries' => 0,
            'active_comments' => 0,
            'deleted_comments' => 0,
            'active_replies' => 0,
            'deleted_replies' => 0,
            'pages_with_active_entries' => 0,
        );
        $page_stats = array();
        $comments = array();
        foreach ($filesItr as $filepath => $file) {
            if ($file->isDir()) {
                // this should never trigger as we are looking vor yamls only
            } else {
                $route = '';
                $fileFolder = substr($filepath, 0, strlen($filepath) - strlen($file->getFilename()) - 1);
                if (!empty($paths[str_replace('/', '\\', $fileFolder)])) $route = $paths[str_replace('/', '\\', $fileFolder)];
                if (!empty($paths[str_replace('\\', '/', $fileFolder)])) $route = $paths[str_replace('\\', '/', $fileFolder)];
                $page_stats[$filepath] = array(
                    'active_entries' => 0,
                    'deleted_entries' => 0,
                    'active_comments' => 0,
                    'deleted_comments' => 0,
                    'active_replies' => 0,
                    'deleted_replies' => 0,
                    'latest_active_entry' => 0,
                    'route' => $route,
                );
                $localfile = CompiledYamlFile::instance($filepath);
                $localcomments = $localfile->content();
                if (!empty($localcomments['comments']) && is_array($localcomments['comments'])) {
                    foreach ($localcomments['comments'] as $comment) {
                        if (!empty($comment['deleted'])) {
                            empty($comment['parent']) ? $page_stats[$filepath]['deleted_comments']++ : $page_stats[$filepath]['deleted_replies']++;
                            empty($comment['parent']) ? $global_stats['deleted_comments']++ : $global_stats['deleted_replies']++;
                            $page_stats[$filepath]['deleted_entries']++;
                            $global_stats['deleted_entries']++;
                        } else {
                            empty($comment['parent']) ? $page_stats[$filepath]['active_comments']++ : $page_stats[$filepath]['active_replies']++;
                            empty($comment['parent']) ? $global_stats['active_comments']++ : $global_stats['active_replies']++;
                            $page_stats[$filepath]['active_entries']++;
                            $global_stats['active_entries']++;
                            
                            //use unix timestamp for comparing and sorting
                            if(is_int($comment['date'])) {
                                $time = $comment['date'];
                            } else {
                                $time = \DateTime::createFromFormat('D, d M Y H:i:s', $comment['date'])->getTimestamp();
                            }
                            
                            if (empty($page_stats[$filepath]['latest_active_entry']) || $page_stats[$filepath]['latest_active_entry'] < $time) {
                                $page_stats[$filepath]['latest_active_entry'] = $time;
                            }
                            
                            $comments[] = array_merge(array(
                                'path' => $filepath,
                                'route' => $route,
                                'time' => $time,
                            ), $comment);
                        }
                    }
                }
                if (!empty($page_stats[$filepath]['latest_active_entry'])) {
                    $global_stats['pages_with_active_entries']++;
                }
            }
        }
        
        //most recent comments first
        usort($comments, function ($a, $b) {
            if ($a['time'] === $b['time']) return 0;
            if ($a['time'] < $b['time']) return 1;
            return -1;
        });
        
        //most recent pages first
            usort($page_stats, function ($a, $b) {
            if ($a['latest_active_entry'] === $b['latest_active_entry']) return 0;
            if ($a['latest_active_entry'] < $b['latest_active_entry']) return 1;
            return -1;
        });

        //reduce comments in output to limit
        if (!empty($limit) && $limit > 0 && $limit < count($comments)) {
            $comments = array_slice($comments, 0, $limit);
        }
        //reduce pages in output to limit
        if (!empty($limit_pages) && $limit_pages > 0 && $limit_pages < count($page_stats)) {
            $page_stats = array_slice($page_stats, 0, $limit_pages);
        }
        
        //save to cache if enabled
        $cache->save($recent_comments_cache_id, ['global_stats' => $global_stats, 'pages' => $page_stats, 'comments' => $comments, 'options' => $options]);
        
        return ['global_stats' => $global_stats, 'pages' => $page_stats, 'comments' => $comments, 'options' => $options];
    }

    private function getFilesOrderedByModifiedDate($path = '') {
        $files = [];

        if (!$path) {
            $path = DATA_DIR . 'comments';
        }

        if (!file_exists($path)) {
            Folder::mkdir($path);
        }

        $dirItr     = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $filterItr  = new RecursiveFolderFilterIterator($dirItr);
        $itr        = new \RecursiveIteratorIterator($filterItr, \RecursiveIteratorIterator::SELF_FIRST);

        $itrItr = new \RecursiveIteratorIterator($dirItr, \RecursiveIteratorIterator::SELF_FIRST);
        $filesItr = new \RegexIterator($itrItr, '/^.+\.yaml$/i');

        // Collect files if modified in the last 7 days
        foreach ($filesItr as $filepath => $file) {
            $modifiedDate = $file->getMTime();
            $sevenDaysAgo = time() - (7 * 24 * 60 * 60);

            if ($modifiedDate < $sevenDaysAgo) {
                continue;
            }

            $files[] = (object)array(
                "modifiedDate" => $modifiedDate,
                "fileName" => $file->getFilename(),
                "filePath" => $filepath,
                "data" => Yaml::parse(file_get_contents($filepath))
            );
        }

        // Traverse folders and recurse
        foreach ($itr as $file) {
            if ($file->isDir()) {
                $this->getFilesOrderedByModifiedDate($file->getPath() . '/' . $file->getFilename());
            }
        }

        // Order files by last modified date
        usort($files, function($a, $b) {
            return !($a->modifiedDate > $b->modifiedDate);
        });

        return $files;
    }

    private function getLastComments($page = 0) {
        $number = 30;

        $files = [];
        $files = $this->getFilesOrderedByModifiedDate();
        $comments = [];

        foreach($files as $file) {
            $data = Yaml::parse(file_get_contents($file->filePath));

            for ($i = 0; $i < count($data['comments']); $i++) {
                $commentTimestamp = \DateTime::createFromFormat('D, d M Y H:i:s', $data['comments'][$i]['date'])->getTimestamp();

                $data['comments'][$i]['pageTitle'] = $data['title'];
                $data['comments'][$i]['filePath'] = $file->filePath;
                $data['comments'][$i]['timestamp'] = $commentTimestamp;
            }
            if (count($data['comments'])) {
                $comments = array_merge($comments, $data['comments']);
            }
        }

        // Order comments by date
        usort($comments, function($a, $b) {
            return !($a['timestamp'] > $b['timestamp']);
        });

        $totalAvailable = count($comments);
        $comments = array_slice($comments, $page * $number, $number);
        $totalRetrieved = count($comments);

        return (object)array(
            "comments" => $comments,
            "page" => $page,
            "totalAvailable" => $totalAvailable,
            "totalRetrieved" => $totalRetrieved
        );
    }

    /**
     * Return the comments associated to the current route
     */
    public function fetchComments() {
        $cache = $this->grav['cache'];
        //search in cache
        if ($comments = $cache->fetch($this->comments_cache_id)) {
            return $comments;
        }

        $lang = $this->grav['language']->getLanguage();
        $filename = $lang ? '/' . $lang : '';
        $filename .= $this->grav['uri']->path() . '.yaml';

        $comments = $this->getDataFromFilename($filename)['comments'];
		$comments = $this->setCommentLevels($comments);
        //save to cache if enabled
        $cache->save($this->comments_cache_id, $comments);
        return $comments;
    }

    /**
     * Return the latest commented pages
     */
    private function setCommentLevels($comments) {
		if(!is_array($comments)) {
			return $comments;
		}
		$levelsflat = array();
		foreach($comments as $key => $comment) {
			if(!empty($comment['deleted'])) {
				//if field "deleted" exists and is filled with a true value then ignore the comment completely.
				//TODO: This only works on this position as long as it is forbidden to delete comments that have active replies (children).
				//      Otherwise implement that children get the deleted flag recursively or are ignored via Comment class.
			} else {
				$levelsflat[$comment['id']]['parent'] = $comment['parent'];
				$levelsflat[$comment['id']]['class'] = new Comment($comment['id'], $comments[$key]);
			}
		}
		//get starting points (entries without valid parent = root element)
		$leveltree = array();
		foreach($levelsflat as $id => $parent) {
			$parent_id = $parent['parent'];
			if(!isset($levelsflat[$parent_id])){
				$leveltree[$id] = $levelsflat[$id]['class'];
			} else {
				$currentParent = $levelsflat[$parent_id]['class'];
				$currentChild = $levelsflat[$id]['class'];
				$levelsflat[$id]['class']->setParent($currentParent);
				$levelsflat[$parent_id]['class']->addSubComment($currentChild);
			}
		}
		//youngest comments first (DESC date), only root comments. Keep replies in ASC date order.
		//as long as comments are not editable, it is sufficient to reverse order from comment file
		$leveltree = array_reverse($leveltree, true);
		//reset comment values to nested order
		$comments = array();
		foreach($leveltree as $id => $comment) {
			$comments = array_merge($comments, $comment->getContent());
		}
		return $comments;
    }

    /**
     * Return the latest commented pages
     */
    private function fetchPages() {
        $files = [];
        $files = $this->getFilesOrderedByModifiedDate();

        $pages = [];

        foreach($files as $file) {
            $pages[] = [
                'title' => $file->data['title'],
                'commentsCount' => count($file->data['comments']),
                'lastCommentDate' => date('D, d M Y H:i:s', $file->modifiedDate)
            ];
        }

        return $pages;
    }


    /**
     * Given a data file route, return the YAML content already parsed
     */
    private function getDataFromFilename($fileRoute) {

        //Single item details
        //$fileInstance = CompiledYamlFile::instance(DATA_DIR . 'comments/' . $fileRoute);
		//Use comment file in page folder
		$fileInstance = CompiledYamlFile::instance($this->grav['page']->path() . '/comments.yaml');

        if (!$fileInstance->content()) {
            //Item not found
            return;
        }

        return $fileInstance->content();
    }

    /**
     * Add templates directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Add plugin templates path
     */
    public function onTwigAdminTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/admin/templates';
    }

    /**
     * Handle the Reindex task from the admin
     *
     * @param Event $e
     */
    public function onAdminTaskExecute(Event $e)
    {
        if ($e['method'] == 'taskReindexComments') {
            $controller = $e['controller'];
            header('Content-type: application/json');
            if (!$controller->authorizeTask('reindexComments', ['admin.configuration', 'admin.super'])) {
                $json_response = [
                    'status'  => 'error',
                    'message' => '<i class="fa fa-warning"></i> Index not created',
                    'details' => 'Insufficient permissions to reindex the comments index file.'
                ];
                echo json_encode($json_response);
                exit;
            }
/*TODO             // disable warnings
            error_reporting(1);
            // capture content
            ob_start();
            $this->gtnt->createIndex();
            ob_get_clean();
            list($status, $msg) = $this->getIndexCount();
            $json_response = [
                'status'  => $status ? 'success' : 'error',
                'message' => '<i class="fa fa-book"></i> ' . $msg
            ];
            echo json_encode($json_response);
 */            exit;
        }
    }

    /**
     * Perform an 'add' or 'update' for comment data as needed
     *
     * @param $event
     * @return bool
     */
    public function onAdminAfterSave($event)
    {
        $obj = $event['object'];
        if ($obj instanceof Page) {
            //nothing to do
			//save means, the page changed, but still exists
        }
        return true;
    }
    /**
     * Perform an 'add' or 'update' for comment data as needed
     *
     * @param $event
     * @return bool
     */
    public function onAdminAfterDelete($event)
    {
        $obj = $event['object'];
        if ($obj instanceof Page) {
            //TODO $this->deleteComment($obj);
            
            //clear cache
            $this->grav['cache']->delete(md5('comments-stats' . $this->grav['cache']->getKey()));
        }
        return true;
    }
    
    /**
     * Add navigation item to the admin plugin
     */
    public function onAdminMenu()
    {
        $this->grav['twig']->plugins_hooked_nav['PLUGIN_COMMENTS.COMMENTS'] = ['route' => $this->route, 'icon' => 'fa-file-text'];
        $options = [
            'authorize' => 'taskReindexComments',
            'hint' => 'reindexes the comments index',
            'class' => 'comments-reindex',
            'icon' => 'fa-file-text'
        ];
        $this->grav['twig']->plugins_quick_tray['PLUGIN_COMMENTS.COMMENTS'] = $options;
    }

    /**
     * Exclude comments from the Data Manager plugin
     */
    public function onDataTypeExcludeFromDataManagerPluginHook()
    {
        $this->grav['admin']->dataTypesExcludedFromDataManagerPlugin[] = 'comments';
    }
}