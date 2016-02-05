<?php
namespace Grav\Plugin;

use Grav\Common\Filesystem\Folder;
use Grav\Common\GPM\GPM;
use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Plugin;
use Grav\Common\Filesystem\RecursiveFolderFilterIterator;
use Grav\Common\User\User;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Yaml\Yaml;

class CommentsPlugin extends Plugin
{
    protected $route = 'comments';
    protected $enable = false;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onFormProcessed' => ['onFormProcessed', 0],
            'onPageInitialized' => ['onPageInitialized', 10],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
        ];
    }

    /**
     * Initialize form if the page has one. Also catches form processing if user posts the form.
     */
    public function onPageInitialized()
    {
        if (!$this->isAdmin()) {
            /** @var Page $page */
            $page = $this->grav['page'];
            if (!$page) {
                return;
            }

            if ($this->enable) {
                $header = $page->header();
                if (!isset($header->form)) {
                    $header->form = $this->grav['config']->get('plugins.comments.form');
                    $page->header($header);
                }
            }
        }
    }

    public function onTwigSiteVariables() {
        if (!$this->isAdmin()) {
            $this->grav['twig']->enable = $this->enable;

            if ($this->enable) {
                $this->grav['twig']->comments = $this->fetchComments();
            }
        }
    }

    /**
     * Determine if $haystack starts with $needle. Credit: http://stackoverflow.com/a/10473026/205039
     */
    private function startsWith($haystack, $needle) {
        return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== FALSE;
    }

    /**
     * Determine if the plugin should be enabled based on the enable_on_routes and disable_on_routes config options
     */
    private function calculateEnable() {
        $uri = $this->grav['uri'];

        $disable_on_routes = (array) $this->config->get('plugins.comments.disable_on_routes');
        $enable_on_routes = (array) $this->config->get('plugins.comments.enable_on_routes');

        $path = $uri->path();

        if (!in_array($path, $disable_on_routes)) {
            if (in_array($path, $enable_on_routes)) {
                $this->enable = true;
            } else {
                foreach($enable_on_routes as $route) {
                    if ($this->startsWith($path, $route)) {
                        $this->enable = true;
                        break;
                    }
                }
            }
        }
    }

    /**
     */
    public function onPluginsInitialized()
    {
        if (!$this->isAdmin()) {

            $this->calculateEnable();

            if ($this->enable) {
                $this->enable([
                    'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
                ]);
            }

        } else {
            /** @var Uri $uri */
            $uri = $this->grav['uri'];

            //Admin
            $this->enable([
                'onTwigTemplatePaths' => ['onTwigAdminTemplatePaths', 0],
                'onAdminMenu' => ['onAdminMenu', 0],
                'onAdminTemplateNavPluginHook' => ['onAdminMenu', 0], //DEPRECATED
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
                $post = !empty($_POST) ? $_POST : [];

                $lang = filter_var(urldecode($post['lang']), FILTER_SANITIZE_STRING);
                $path = filter_var(urldecode($post['path']), FILTER_SANITIZE_STRING);
                $text = filter_var(urldecode($post['text']), FILTER_SANITIZE_STRING);
                $name = filter_var(urldecode($post['name']), FILTER_SANITIZE_STRING);
                $email = filter_var(urldecode($post['email']), FILTER_SANITIZE_STRING);
                $title = filter_var(urldecode($post['title']), FILTER_SANITIZE_STRING);

                /** @var Language $language */
                $language = $this->grav['language'];
                $lang = $language->getLanguage();

                $filename = DATA_DIR . 'comments';
                $filename .= ($lang ? '/' . $lang : '');
                $filename .= $path . '.yaml';
                $file = File::instance($filename);

                if (file_exists($filename)) {
                    $data = Yaml::parse($file->content());

                    $data['comments'][] = [
                        'text' => $text,
                        'date' => date('D, d M Y H:i:s', time()),
                        'author' => $name,
                        'email' => $email
                    ];
                } else {
                    $data = array(
                        'title' => $title,
                        'lang' => $lang,
                        'comments' => array([
                            'text' => $text,
                            'date' => date('D, d M Y H:i:s', time()),
                            'author' => $name,
                            'email' => $email
                        ])
                    );
                }

                $file->save(Yaml::dump($data));
                break;
        }
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
                $sevenDaysAgo = time() - (7 * 24 * 60 * 60);

                if ($commentTimestamp < $sevenDaysAgo) {
                    continue;
                }

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
    private function fetchComments() {
        $lang = $this->grav['language']->getLanguage();
        $filename = $lang ? '/' . $lang : '';
        $filename .= $this->grav['uri']->path() . '.yaml';

        return $this->getDataFromFilename($filename)['comments'];
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
        $fileInstance = File::instance(DATA_DIR . 'comments/' . $fileRoute);

        if (!$fileInstance->content()) {
            //Item not found
            return;
        }

        return Yaml::parse($fileInstance->content());
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
     * Add navigation item to the admin plugin
     */
    public function onAdminMenu()
    {
        $this->grav['twig']->plugins_hooked_nav['PLUGIN_COMMENTS.COMMENTS'] = ['route' => $this->route, 'icon' => 'fa-file-text'];
    }

    /**
     * Exclude comments from the Data Manager plugin
     */
    public function onDataTypeExcludeFromDataManagerPluginHook()
    {
        $this->grav['admin']->dataTypesExcludedFromDataManagerPlugin[] = 'comments';
    }
}
