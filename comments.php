<?php
namespace Grav\Plugin;

use Grav\Common\GPM\GPM;
use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\Event\Event;
use Grav\Common\Filesystem\RecursiveFolderFilterIterator;
use Symfony\Component\Yaml\Yaml;

class CommentsPlugin extends Plugin
{
    protected $route = 'comments';

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    /**
     */
    public function onPluginsInitialized()
    {
        if (!$this->isAdmin()) {

            $this->enable([
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            ]);

            $this->addCommentURL = $this->config->get('plugins.comments.addCommentURL', '/add-comment');

            if ($this->addCommentURL && $this->addCommentURL == $this->grav['uri']->path()) {
                $this->enable([
                    'onPagesInitialized' => ['addComment', 0]
                ]);
            } else {
                $this->grav['twig']->comments = $this->fetchComments();
            }

        } else {
            //Admin
            $this->enable([
                'onTwigTemplatePaths' => ['onTwigAdminTemplatePaths', 0],
                'onAdminTemplateNavPluginHook' => ['onAdminTemplateNavPluginHook', 0],
                'onDataTypeExcludeFromDataManagerPluginHook' => ['onDataTypeExcludeFromDataManagerPluginHook', 0],
            ]);

            $this->grav['twig']->files = $this->getFilesOrderedByModifiedDate();
        }
    }

    public function addComment()
    {
        $post = !empty($_POST) ? $_POST : [];

        $lang = filter_var(urldecode($post['lang']), FILTER_SANITIZE_STRING);
        $path = filter_var(urldecode($post['path']), FILTER_SANITIZE_STRING);
        $text = filter_var(urldecode($post['text']), FILTER_SANITIZE_STRING);
        $name = filter_var(urldecode($post['name']), FILTER_SANITIZE_STRING);
        $email = filter_var(urldecode($post['email']), FILTER_SANITIZE_STRING);
        $title = filter_var(urldecode($post['title']), FILTER_SANITIZE_STRING);

        $filename = DATA_DIR . 'comments';
        $filename .= ($lang ? '/' . $lang : '');
        $filename .= $path . '.yaml';
        $file = File::instance($filename);

        if (file_exists($filename)) {
            $data = Yaml::parse($file->content());

            $data['comments'][] = [
                'text' => $text,
                'date' => gmdate('D, d M Y H:i:s', time()),
                'author' => $name,
                'email' => $email
            ];
        } else {
            $data = array(
                'title' => $title,
                'lang' => $lang,
                'comments' => array([
                    'text' => $text,
                    'date' => gmdate('D, d M Y H:i:s', time()),
                    'author' => $name,
                    'email' => $email
                ])
            );
        }

        $file->save(Yaml::dump($data));

        exit();
    }

    private function getFilesOrderedByModifiedDate($path = '') {
        $files = [];

        if (!$path) {
            $path = DATA_DIR . 'comments';
        }

        $dirItr     = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
        $filterItr  = new RecursiveFolderFilterIterator($dirItr);
        $itr        = new \RecursiveIteratorIterator($filterItr, \RecursiveIteratorIterator::SELF_FIRST);

        $itrItr = new \RecursiveIteratorIterator($dirItr, \RecursiveIteratorIterator::SELF_FIRST);
        $filesItr = new \RegexIterator($itrItr, '/^.+\.yaml$/i');

        foreach ($filesItr as $filepath => $file) {
            $files[] = (object)array(
                "modifiedDate" => $file->getMTime(),
                "fileName" => $file->getFilename(),
                "filePath" => $filepath,
                "data" => Yaml::parse(file_get_contents($filepath))
            );
        }

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

    /**
     * Return the comments associated to the current route
     */
    private function fetchComments() {
        $lang = $this->grav['language']->getActive();
        $filename = $lang ? '/' . $lang : '';
        $filename .= $this->grav['uri']->path() . '.yaml';

        return $this->getDataFromFilename($filename)['comments'];
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
    public function onAdminTemplateNavPluginHook()
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
