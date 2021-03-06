<?php
namespace Grav\Plugin\TNTSearch;

use Grav\Common\Grav;
use Grav\Common\Page\Collection;
use Grav\Common\Page\Page;
use RocketTheme\Toolbox\Event\Event;
use TeamTNT\TNTSearch\Exceptions\IndexNotFoundException;
use TeamTNT\TNTSearch\Exceptions\LangNotFoundException;
use TeamTNT\TNTSearch\TNTSearch;

class GravTNTSearch
{
    public $tnt;
    protected $options;
    protected $bool_characters = ['-', '(', ')', 'or'];

    public function __construct($options = [])
    {
        $search_type = Grav::instance()['config']->get('plugins.multilang-tntsearch.search_type');
        $stemmer = Grav::instance()['config']->get('plugins.multilang-tntsearch.stemmer');
        $data_path = Grav::instance()['locator']->findResource('user://data', true) . '/tntsearch';
        
        if (!file_exists($data_path)) {
            mkdir($data_path);
        }

        $defaults = [
            'json' => false,
            'search_type' => $search_type,
            'stemmer' => $stemmer,
            'limit' => 20,
            'as_you_type' => true,
            'snippet' => 300,
        ];
        //dump($options);
        $this->options = array_merge($defaults, $options);
//        dump($this->options);
        //dump(Grav::instance()['config']['system']['languages']['supported']);
        //$lang = new \Grav\Common\Language\Language(Grav::instance());
        //dump(Grav::instance()['language']->getActive());
        
        $this->tnt = new TNTSearch();
        $this->tnt->loadConfig([
            "storage"   => $data_path,
            "driver"    => 'sqlite',
        ]);
    }

    public function search($query) {
        $uri = Grav::instance()['uri'];
        $type = $uri->query('search_type');
//        $this->tnt->selectIndex('grav.index');
        
        //BBA
        $lang = Grav::instance()['language']->getActive();
        $this->tnt->selectIndex("grav.$lang.index");
        $this->tnt->asYouType = $this->options['as_you_type'];
        //FIN BBA
        if (isset($this->options['fuzzy']) && $this->options['fuzzy']) {
            $this->tnt->fuzziness = true;
        }
      

        $limit = intval($this->options['limit']);
        $type = isset($type) ? $type : $this->options['search_type'];
        //dump( $this->options['fuzzy'], $type);
        switch ($type) {
            case 'basic':
                $results = $this->tnt->search($query, $limit);
                break;
            case 'boolean':
                $results = $this->tnt->searchBoolean($query, $limit);
                break;
            case 'default':
            case 'auto':
            default:
                $guess = 'search';
                foreach ($this->bool_characters as $char) {
                    if (strpos($query, $char) !== false) {
                        $guess = 'searchBoolean';
                    }
                }

                $results = $this->tnt->$guess($query, $limit);
                //dump($guess);
                //dump($query);
                //dump($results);
        }
        //dump();
        return $this->processResults($results, $query);
    }

    protected function processResults($res, $query)
    {
        $counter = 0;
        $data = new \stdClass();
        $data->number_of_hits = isset($res['hits']) ? $res['hits'] : 0;
        $data->execution_time = $res['execution_time'];
        $pages = Grav::instance()['pages'];

        foreach ($res['ids'] as $path) {

            if ($counter++ > $this->options['limit']) {
                break;
            }

            $page = $pages->dispatch($path);

            if ($page) {
                Grav::instance()->fireEvent('onTNTSearchQuery', new Event(['page' => $page, 'query' => $query, 'options' => $this->options, 'fields' => $data, 'gtnt' => $this]));
            }
        }

        if ($this->options['json']) {
            return json_encode($data, JSON_PRETTY_PRINT);
        } else {
            return $data;
        }
    }

    public static function getCleanContent($page)
    {
        
        $twig = Grav::instance()['twig'];
        $header = $page->header();

        if (isset($header->tntsearch['template'])) {
            $processed_page = $twig->processTemplate($header->tntsearch['template'] . '.html.twig', ['page' => $page]);
            $content =$processed_page;
        } else {
            $content = $page->content();
        }

        $content = preg_replace('/[ \t]+/', ' ', preg_replace('/\s*$^\s*/m', "\n", strip_tags($content)));

        return $content;
    }

//    public function createIndex() 
    public function createIndex( $lang )//bba
    {
       // $lang = isset($lang)? $lang: Grav::instance()['language']->getActive();//bba
        $languages = Grav::instance()['config']['system']['languages']['supported'];
        if(!in_array($lang, $languages))
        {
            echo in_array($lang, $languages);
            echo (implode($languages));
            echo($lang);
            throw new LangNotFoundException("I Dont want to create an index as lang $lang is not supported");
        }
        if(!isset($lang))
        {
            throw new LangNotFoundException("Cannot create Index lang is not set");
        }
        echo "Creating INDEX for $lang \n";
        $connector = new GravConnector();
        $connector->setLang($lang);//bba
        $this->tnt->setDatabaseHandle($connector);
        $indexer = $this->tnt->createIndex("grav.$lang.index");
        // Set the stemmer language
        switch ($lang)
        {
            //case 'de' : $indexer->setLanguage('german');
            case 'ru' : $indexer->setLanguage('russian');
            case 'it' : $indexer->setLanguage('italian');
            case 'ar' : $indexer->setLanguage('arabic');
            case 'hr' : $indexer->setLanguage('croatian');
            case 'uk' : $indexer->setLanguage('ukrainian');
            default : $indexer->setLanguage('porter');
        }
        $indexer->run(); //BBA
        
    }
    /**
     * BBA
     */
    public function createIndexes()
    {
        $languages = Grav::instance()['config']['system']['languages']['supported'];
        foreach ($languages as $lang)
        {
            $this->createIndex($lang);
        }
        
    }
    public function deleteIndex($page)
    {

        $lang = isset($lang)? $lang: Grav::instance()['language']->getActive();//bba
        $this->tnt->setDatabaseHandle(new GravConnector($lang));//bba
        //        $this->tnt->setDatabaseHandle(new GravConnector);
        try {
//            $this->tnt->selectIndex('grav.index');
            $this->tnt->selectIndex("grav.$lang.index");
        } catch (IndexNotFoundException $e) {
            return;
        }

        $indexer = $this->tnt->getIndex();

        // Delete existing if it exists
        $indexer->delete($page->route());
    }

    public function updateIndex($page)
    {
         $lang = isset($lang)? $lang: Grav::instance()['language']->getActive();//bba
        $this->tnt->setDatabaseHandle(new GravConnector($lang));//bba
//        $this->tnt->setDatabaseHandle(new GravConnector);

        try {
//            $this->tnt->selectIndex('grav.index');
            $this->tnt->selectIndex("grav.$lang.index");
        } catch (IndexNotFoundException $e) {
            return;
        }

        $indexer = $this->tnt->getIndex();

        // Delete existing if it exists
        $indexer->delete($page->route());

        $filter = $config = Grav::instance()['config']->get('plugins.tntsearch-multilang.filter');
        if ($filter && array_key_exists('items', $filter)) {

            if (is_string($filter['items'])) {
                $filter['items'] = Yaml::parse($filter['items']);
            }

            $apage = new Page;
            /** @var Collection $collection */
            $collection = $apage->collection($filter, false);

            if (array_key_exists($page->path(), $collection->toArray())) {
                $fields = GravTNTSearch::indexPageData($page);
                $document = (array) $fields;

                // Insert document
                $indexer->insert($document);
            }
        }
    }

    public function indexPageData($page, $route)
    {
        $fields = new \stdClass();
//        $fields->id = $page->route();
        $fields->id = $route;//bba
        //$route = $page->route();//BBA
        //echo "Route : {$fields->id}";//BBA
        $fields->name = $page->title();
       
        $fields->content = $this->getCleanContent($page);

        Grav::instance()->fireEvent('onTNTSearchIndex', new Event(['page' => $page, 'fields' => $fields]));

        return $fields;
    }

}
