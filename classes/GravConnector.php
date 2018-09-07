<?php
namespace Grav\Plugin\TNTSearch;

use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Symfony\Component\Yaml\Yaml;

class GravConnector extends \PDO
{
    protected $lang;
    public function __construct()
    {
        
    }
    /**
     * BBA
     * @param type $attribute
     * @return boolean
     */
    public function setLang($language = "")
    {
        $this->lang = $language;
    }
    public function getAttribute($attribute)
    {
        return false;
    }

    public function query($query)
    {
        $counter = 0;
        $results = [];

        $config = Grav::instance()['config'];
        $filter = $config->get('plugins.tntsearch.filter');
        $default_process = $config->get('plugins.tntsearch.index_page_by_default');
        $gtnt = new GravTNTSearch();
        //Grav::instance()['language']->setActive("de");
//       echo (Grav::instance()['language']->getActive());


        if ($filter && array_key_exists('items', $filter)) {
//            echo (var_dump($filter));
            if (is_string($filter['items'])) {
                $filter['items'] = Yaml::parse($filter['items']);
            }

            $page = new Page;
            $collection = $page->collection($filter, false);
//            echo(count($collection) ."\n");
        } else {
//            echo "no filter";
            $collection = Grav::instance()['pages']->all();
            $collection->published()->routable();
        }

        foreach ($collection as $page) {
            $counter++;
            //echo($counter."\n");
            $process = $default_process;
            $header = $page->header();
            $route = $page->route();
            //echo "Route A $route";
            //bba
            $langPagePath = $page->path() . DS . $page->template() . '.' . $this->lang . '.md';
            $newPage = new Page();
            if(file_exists($langPagePath))
            {
               // echo $langPagePath ."\n";
                $newPage->init(new \SplFileInfo($langPagePath), $this->lang  . '.md');
                //$newPage->route = $route;
                //$newPage->name = $route;
                //echo $newPage->title();
                //echo "\n";
                //echo $newPage->rawRoute();
                //echo "\n";
            }
            else{
                $newPage = $page;
            }
           // $page->init();
//            $template = $page->template();
            
           // Grav::instance()['language']->setActive("de");
            
            //$file = $newPage->file()->basename();
            
            //echo("Template: $template\n");
//            echo("File: $file\n");

            if (isset($header->tntsearch['process'])) {
                $process = $header->tntsearch['process'];
            }

            // Only process what's configured
            if (!$process) {
                echo("Skipped $counter $route\n");
                continue;
            }

            try {
                $fields = $gtnt->indexPageData($newPage, $route);
//                $fields = $gtnt->indexPageData($page);
                $results[] = (array) $fields;
                echo("Added $counter $route {$this->lang} \n");//BBA
            } catch (\Exception $e) {
                echo($e->getCode()."\n");
                echo($e->getLine()."\n");
                echo($e->getFile()."\n");
                echo($e->getMessage()."\n");
                echo("-----------------------\n");
                echo($e->getTraceAsString()."\n");
                echo("Skipped $counter $route\n");
                echo("-----------------------\n");
                continue;
            }
        }
        
        return new GravResultObject($results);
    }

}

