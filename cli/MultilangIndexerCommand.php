<?php
namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Symfony\Component\Console\Input\InputArgument;
use Grav\Plugin\TNTSearch\GravTNTSearch;

/**
 * Class IndexerCommand
 *
 * @package Grav\Plugin\Console
 */
class MultilangIndexerCommand extends ConsoleCommand
{
    /**
     * @var array
     */
    protected $options = [];
    /**
     * @var array
     */
    protected $colors = [
        'DEBUG'     => 'green',
        'INFO'      => 'cyan',
        'NOTICE'    => 'yellow',
        'WARNING'   => 'yellow',
        'ERROR'     => 'red',
        'CRITICAL'  => 'red',
        'ALERT'     => 'red',
        'EMERGENCY' => 'magenta'
    ];

    /**
     *
     */
    protected function configure()
    {
        $this
            ->setName("indexLang")
            ->setDescription("TNTSearch Indexer")
            ->addArgument(
                    "lang", 
                    InputArgument::REQUIRED, 
                    "Language to index FR or DE")
            ->setHelp('The <info>indexLang command</info> re-indexes the search engine of a particular (FR or DE) language.');
    }

    /**
     * @return int|null|void
     */
    protected function serve()
    {
        try{
            $lang = $this->input->getArgument("lang");
            $this->output->writeln('');
            $this->output->writeln('<magenta>Re-indexing Search</magenta>');
            $this->output->writeln('');

            $this->doIndex(strtolower($lang));
            $this->output->writeln('<green>Done</green>');
        }
        catch ( RuntimeException  $e)
        {
            $this->output->writeln("<error>Re-indexing Search Excption found $e</error>");
             $this->output->writeln('<red>Not DONE</red>');
        }
        
    }

    private function doIndex($lang)
    {
        include __DIR__.'/../vendor/autoload.php';
        error_reporting(1);

        $grav = Grav::instance();
        $grav['debugger']->enabled(false);
        $grav['twig']->init();
        $grav['pages']->init();

        $gtnt = new GravTNTSearch();
//        $gtnt->createIndex();
        $gtnt->createIndex($lang);
    }
}

