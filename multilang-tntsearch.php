<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;

use Grav\Common\Page\Page;
//use Grav\Common\Plugin;
use Grav\Plugin\TNTSearch\GravTNTSearch;
//use RocketTheme\Toolbox\Event\Event;
use TeamTNT\TNTSearch\Exceptions\IndexNotFoundException;


/**
 * Class MultilangTntsearchPlugin
 * @package Grav\Plugin
 */
class MultilangTntsearchPlugin extends Plugin
{
    protected $results = [];
    protected $query;

    protected $built_in_search_page;
    protected $query_route;
    protected $search_route;
    protected $current_route;
    protected $admin_route;
    //bba
    protected $callerPage;

    /** @var  GravTNTSearch **/
    protected $gtnt;
    const PLUGIN_NAME = 'multilang-tntsearch';

    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            'onTwigLoader' => ['onTwigLoader', 0],
            'onTNTSearchReIndex' => ['onTNTSearchReIndex', 0],
            'onTNTSearchIndex' => ['onTNTSearchIndex', 0],
            'onTNTSearchQuery' => ['onTNTSearchQuery', 0],
        ];
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        include __DIR__.'/vendor/autoload.php';

        if ($this->isAdmin()) {

            $this->gtnt = new GravTNTSearch();
            $route = $this->config->get('plugins.admin.route');
            $base = '/' . trim($route, '/');
            $this->admin_route = $this->grav['base_url'] . $base;

            $this->enable([
                'onAdminMenu' => ['onAdminMenu', 0],
                'onAdminTaskExecute' => ['onAdminTaskExecute', 0],
                'onAdminAfterSave' => ['onAdminAfterSave', 0],
                'onAdminAfterDelete' => ['onAdminAfterDelete', 0],
                'onTwigSiteVariables' => ['onTwigAdminVariables', 0],
                'onTwigLoader' => ['addAdminTwigTemplates', 0],
            ]);
            return;
        }

        $this->enable([
            'onPagesInitialized' => ['onPagesInitialized', 1000],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
        ]);
    }

    /**
     * Function to force a reindex from your own plugins
     */
    public function onTNTSearchReIndex()
    {
        $this->gtnt->createIndex();
    }

    /**
     * A sample event to show how easy it is to extend the indexing fields
     *
     * @param Event $e
     */
    public function onTNTSearchIndex(Event $e)
    {
        $page = $e['page'];
        $fields = $e['fields'];

        if (isset($page->header()->author)) {
            $fields->author = $page->header()->author;
        }
        if (isset($taxonomy['tag'])) {
            $fields->tag = implode(",", $taxonomy['tag']);
        }
        if (isset($taxonomy['audience'])) {
            $fields->tag = implode(",", $taxonomy['audience']);
        }
        if (isset($taxonomy['context'])) {
            $fields->tag = implode(",", $taxonomy['context']);
        }
    }

    public function onTNTSearchQuery(Event $e)
    {
        
        $page = $e['page'];
//        dump("query results", $this->current_route);
        $query = $e['query'];
        $options = $e['options'];
        $fields = $e['fields'];
        $gtnt = $e['gtnt'];
//        dump($options);
        $content = $gtnt->getCleanContent($page);
        $title = $page->title();

        $relevant = $gtnt->tnt->snippet($query, $content, $options['snippet']);

        if (strlen($relevant) <= 6) {
            $relevant = substr($content, 0, $options['snippet']);
        }

        $fields->hits[] = [
            'link' => $page->route(),
            'title' =>  $gtnt->tnt->highlight($title, $query, 'em', ['wholeWord' => false]),
            'content' =>  $gtnt->tnt->highlight($relevant, $query, 'em', ['wholeWord' => false]),
        ];
    }

    /**
     * Create pages and perform the search actions
     */
    public function onPagesInitialized()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];
        //bba
        $lang = new \Grav\Common\Language\Language($this->grav);
        $current = $lang->getActive();
//        dump($this->grav['language']->getActive());
        
        //end bba
        $options = [];

        $this->current_route = $uri->path();

        $this->built_in_search_page = $this->config->get('plugins.'.self::PLUGIN_NAME.'.built_in_search_page');
        $this->search_route = $this->config->get('plugins.'.self::PLUGIN_NAME.'.search_route');
        $this->query_route = $this->config->get('plugins.'.self::PLUGIN_NAME.'.query_route');
       
        $this->query = $uri->param('q') ?: $uri->query('q');

        $snippet = $this->getFormValue('sl');
        $limit = $this->getFormValue('l');
        

        if ($snippet) {
            $options['snippet'] = $snippet;
        }
        if ($limit) {
            $options['limit'] = $limit;
        }
        /**
         * BBA
         */
        $fuzzy = $this->config->get('plugins.'.self::PLUGIN_NAME.'.fuzzy');
        if($fuzzy)
        {
            $options['fuzzy'] = $fuzzy;
        }
//        $stemmer = $this->config->get('plugins.multilang-tntsearch.stemmer');
//        if(isset($stemmer))
//        {
//            $options['stemmer'] = $stemmer;
//        }
//        dump( $options['stemmer'] );
        
        //echo("<script>console.log(".join(" | ",$options)."</script>");
        $this->gtnt = new GravTNTSearch($options);

        $pages = $this->grav['pages'];
        $page = $pages->dispatch($this->current_route);
//        dump($this->current_route);
        if (!$page) {
            if ($this->query_route && $this->query_route == $this->current_route) {
                $page = new Page;
                $page->init(new \SplFileInfo(__DIR__ . "/pages/tntquery.md"));
                $page->slug(basename($this->current_route));
                if ($uri->param('ajax') || $uri->query('ajax')) {
//                    $page->template('tntquery-ajax');
                    $page->template(self::PLUGIN_NAME.'-ajax');
                }
                $pages->addPage($page, $this->current_route);
            } elseif ($this->built_in_search_page && $this->search_route == $this->current_route) {
                $page = new Page;
                $page->init(new \SplFileInfo(__DIR__ . "/pages/search.md"));
                $page->slug(basename($this->current_route));
                $pages->addPage($page, $this->current_route);
            }
        }

        if ($page) {
//            dump($page);
            $this->config->set('plugins.'.self::PLUGIN_NAME, $this->mergeConfig($page));
//            dump($this->config());
        }

        try {
            $this->results = $this->gtnt->search($this->query);
        } catch (IndexNotFoundException $e) {
            $this->results = ['number_of_hits' => 0, 'hits' => [], 'execution_time' => 'missing index'];
        }
//        dump($this->current_route);
    }

    /**
     * Add the Twig template paths to the Twig laoder
     */
    public function onTwigLoader()
    {
        $this->grav['twig']->addPath(__DIR__ . '/templates');
    }

    /**
     * Add the current template paths to the admin Twig loader
     */
    public function addAdminTwigTemplates()
    {
        $this->grav['twig']->addPath($this->grav['locator']->findResource('theme://templates'));
    }

    /**
     * Add results and query to Twig as well as CSS/JS assets
     */
    public function onTwigSiteVariables()
    {
        $twig = $this->grav['twig'];

        if ($this->query) {
            $twig->twig_vars['query'] = $this->query;
           // dump($twig->twig_vars['query'] );
            $twig->twig_vars['tntsearch_results'] = $this->results;
        }
        //dump($this->config->get('plugins.'.self::PLUGIN_NAME.'.built_in_css'));
        //dump($this->config->get('plugins.'.self::PLUGIN_NAME.'.built_in_js'));
        if ($this->config->get('plugins.'.self::PLUGIN_NAME.'.built_in_css')) {
            $this->grav['assets']->addCss('plugin://'.self::PLUGIN_NAME.'/assets/tntsearch.css');
        }
        else{
            $this->grav['assets']->addCss('theme://css/tntsearch.css');
        }
        if ($this->config->get('plugins.'.self::PLUGIN_NAME.'.built_in_js')) {
             $this->grav['assets']->addJs('plugin://'.self::PLUGIN_NAME.'/assets/tntsearch.js');
        }
        else{
             $this->grav['assets']->addJs('theme://js/tntsearch.js');//bba
        }
    }

    /**
     * Handle the Reindex task from the admin
     *
     * @param Event $e
     */
    public function onAdminTaskExecute(Event $e)
    {
        if ($e['method'] == 'taskReindexTNTSearch') {

            $controller = $e['controller'];

            header('Content-type: application/json');

            if (!$controller->authorizeTask('reindexTNTSearch', ['admin.configuration', 'admin.super'])) {
                $json_response = [
                    'status'  => 'error',
                    'message' => '<i class="fa fa-warning"></i> Index not created',
                    'details' => 'Insufficient permissions to reindex the search engine database.'
                ];
                echo json_encode($json_response);
                exit;
            }

            // disable warnings
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
            exit;
        }

    }



    /**
     * Perform an 'add' or 'update' for index data as needed
     *
     * @param $event
     * @return bool
     */
    public function onAdminAfterSave($event)
    {
        $obj = $event['object'];

        if ($obj instanceof Page) {
            $this->gtnt->updateIndex($obj);
        }

        return true;
    }

    /**
     * Perform an 'add' or 'update' for index data as needed
     *
     * @param $event
     * @return bool
     */
    public function onAdminAfterDelete($event)
    {
        $obj = $event['object'];

        if ($obj instanceof Page) {
            $this->gtnt->deleteIndex($obj);
        }

        return true;
    }

    /**
     * Set some twig vars and load CSS/JS assets for admin
     */
    public function onTwigAdminVariables()
    {
        $twig = $this->grav['twig'];

        list($status, $msg) = $this->getIndexCount();

        if ($status === false) {
            $message = '<i class="fa fa-binoculars"></i> <a href="/'. trim($this->admin_route, '/') . '/plugins//'.self::PLUGIN_NAME.'">TNTSearch must be indexed before it will function properly.</a>';
            $this->grav['admin']->addTempMessage($message, 'error');
        }

        $twig->twig_vars['tntsearch_index_status'] = ['status' => $status, 'msg' => $msg];
        $this->grav['assets']->addCss('plugin://'.self::PLUGIN_NAME.'/assets/admin/tntsearch.css');
        $this->grav['assets']->addJs('plugin://'.self::PLUGIN_NAME.'/assets/admin/tntsearch.js');
    }

    /**
     * Add reindex button to the admin QuickTray
     */
    public function onAdminMenu()
    {
        $options = [
            'authorize' => 'taskReindexTNTSearch',
            'hint' => 'reindexes the TNT Search index',
            'class' => 'tntsearch-reindex',
            'icon' => 'fa-binoculars'
        ];
        $this->grav['twig']->plugins_quick_tray['TNT Search'] = $options;
    }

    /**
     * Wrapper to get the number of documents currently indexed
     *
     * @return array
     */
    protected function getIndexCount()
    {
        $status = true;
        try {
            $this->gtnt->tnt->selectIndex('grav.index');
            $msg = $this->gtnt->tnt->totalDocumentsInCollection() . ' documents indexed';
        } catch (IndexNotFoundException $e) {
            $status = false;
            $msg = "Index not created";
        }

        return [$status, $msg];
    }

    /**
     * Helper function to read form/url values
     *
     * @param $val
     * @return mixed
     */
    protected function getFormValue($val)
    {
        $uri = $this->grav['uri'];
        //dump($uri);
        return $uri->param($val) ?: $uri->query($val) ?: filter_input(INPUT_POST, $val, FILTER_SANITIZE_ENCODED);;
    }


}
