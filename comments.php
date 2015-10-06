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

            // //Site
            // $this->enable([
            //     'onPageProcessed' => ['onPageProcessed', 0],
            // ]);


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
        $filename = DATA_DIR . 'comments' . $post['path'] . '.yaml';
        $file = File::instance($filename);

        if (file_exists($filename)) {
            $data = Yaml::parse($file->content());

            $data['comments'][] = [
                'text' => $post['text'],
                'date' => gmdate('D, d M Y H:i:s', time()),
                'author' => $post['name'],
                'email' => $post['email']
            ];
        } else {
            $data = array(
                'comments' => array([
                    'text' => $post['text'],
                    'date' => gmdate('D, d M Y H:i:s', time()),
                    'author' => $post['name'],
                    'email' => $post['email']
                ])
            );
        }

        $file->save(Yaml::dump($data));

        exit();
    }

    private function getFilesOrderedByModifiedDate($path = '') {
        $files = [];
        $dirItr     = new \RecursiveDirectoryIterator(DATA_DIR . 'comments' . $path, \RecursiveDirectoryIterator::SKIP_DOTS);
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
                $this->getFilesOrderedByModifiedDate('/' . $file->getFilename());
            }
        }

        // Order files by last modified date
        usort($files, function($a, $b) {
            return !($a->modifiedDate > $b->modifiedDate);
        });

        return $files;
    }



    private function fetchComments() {


        return $this->getFileContentFromRoute($this->grav['uri']->path() . '.yaml')['comments'];





        // return [
        //     'route' => 'comment-test-1',
        //     'content' => 'A comment text'
        // ];
    }

    /**
     * Given a data file route, return the YAML content already parsed
     */
    private function getFileContentFromRoute($fileRoute) {

        //Single item details
        $fileInstance = File::instance(DATA_DIR . 'comments/' . $fileRoute);

        if (!$fileInstance->content()) {
            //Item not found
            return;
        }

        return Yaml::parse($fileInstance->content());
    }

    // /**
    //  */
    // public function onPageProcessed(Event $e)
    // {
    //     $page = $e['page'];
    //     $page->setRawContent('ss');
    // }

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
