<?php

namespace TeamTNT\TNTSearch;

use PDO;
use TeamTNT\TNTSearch\Exceptions\IndexNotFoundException;
use TeamTNT\TNTSearch\Indexer\TNTIndexer;
use TeamTNT\TNTSearch\Stemmer\PorterStemmer;
use TeamTNT\TNTSearch\Support\Collection;
use TeamTNT\TNTSearch\Support\Expression;
use TeamTNT\TNTSearch\Support\Highlighter;
use TeamTNT\TNTSearch\Support\Tokenizer;
use TeamTNT\TNTSearch\Support\TokenizerInterface;

class TNTSearch
{
    public $config;
    public $asYouType            = false;
    public $maxDocs              = 500;
    public $tokenizer            = null;
    public $index                = null;
    public $stemmer              = null;
    public $fuzziness            = false;
    public $fuzzy_prefix_length  = 2;
    public $fuzzy_max_expansions = 50;
    public $fuzzy_distance       = 2;
    protected $dbh               = null;

    /**
     * @param array $config
     *
     * @see https://github.com/teamtnt/tntsearch#examples
     */
    public function loadConfig(array $config)
    {
        $this->config            = $config;
        $this->config['storage'] = rtrim($this->config['storage'], '/').'/';
    }

    public function __construct()
    {
        $this->tokenizer = new Tokenizer;
    }

    /**
     * @param PDO $dbh
     */
    public function setDatabaseHandle(PDO $dbh)
    {
        $this->dbh = $dbh;
    }

    /**
     * @param TokenizerInterface $tokenizer
     */
    public function setTokenizer(TokenizerInterface $tokenizer)
    {
        $this->tokenizer = $tokenizer;
    }

    /**
     * @param string $indexName
     *
     * @return TNTIndexer
     */
    public function createIndex($indexName)
    {
        $indexer = new TNTIndexer;
        $indexer->loadConfig($this->config);

        if ($this->dbh) {
            $indexer->setDatabaseHandle($this->dbh);
        }
        return $indexer->createIndex($indexName);
    }

    /**
     * @param string $indexName
     *
     * @throws IndexNotFoundException
     */
    public function selectIndex($indexName)
    {
        $pathToIndex = $this->config['storage'].$indexName;
        //dump($pathToIndex);
        if (!file_exists($pathToIndex)) {
            throw new IndexNotFoundException("Index {$pathToIndex} does not exist", 1);
        }
        $this->index = new PDO('sqlite:'.$pathToIndex);
        $this->index->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->setStemmer();
    }

    /**
     * @param string $phrase
     * @param int    $numOfResults
     *
     * @return array
     */
    public function search($phrase, $numOfResults = 100)
    {
        //dump("search");
        $startTimer = microtime(true);
        $keywords   = $this->breakIntoTokens($phrase);
        $keywords   = new Collection($keywords);

        $keywords = $keywords->map(function ($keyword) {
            return $this->stemmer->stem($keyword);
        });

        $tfWeight  = 1;
        $dlWeight  = 0.5;
        $docScores = [];
        $count     = $this->totalDocumentsInCollection();

        foreach ($keywords as $index => $term) {
            $isLastKeyword = ($keywords->count() - 1) == $index;
            $df            = $this->totalMatchingDocuments($term, $isLastKeyword);
            foreach ($this->getAllDocumentsForKeyword($term, false, $isLastKeyword) as $document) {
                //dump($document);
                $docID = $document['doc_id'];
                $tf    = $document['hit_count'];
                $idf   = log($count / $df);
                $num   = ($tfWeight + 1) * $tf;
                $denom = $tfWeight
                     * ((1 - $dlWeight) + $dlWeight)
                     + $tf;
                $score             = $idf * ($num / $denom);
                $docScores[$docID] = isset($docScores[$docID]) ?
                $docScores[$docID] + $score : $score;
            }
        }

        arsort($docScores);

        $docs = new Collection($docScores);

        $counter   = 0;
        $totalHits = $docs->count();
        $docs      = $docs->map(function ($doc, $key) {
            return $key;
        })->filter(function ($item) use (&$counter, $numOfResults) {
            $counter++;
            if ($counter <= $numOfResults) {
                return true;
            }
            return false; // ?
        });
        $stopTimer = microtime(true);

        if ($this->isFileSystemIndex()) {
            return $this->filesystemMapIdsToPaths($docs)->toArray();
        }
        return [
            'ids'            => array_keys($docs->toArray()),
            'hits'           => $totalHits,
            'execution_time' => round($stopTimer - $startTimer, 7) * 1000 ." ms"
        ];
    }

    /**
     * @param string $phrase
     * @param int    $numOfResults
     *
     * @return array
     */
    public function searchBoolean($phrase, $numOfResults = 100)
    {
        //dump("searchBoolean");
//        dump($phrase);
//        dump($numOfResults);
//        dump($this->index);
//        dump($this->stemmer);
        $stack      = [];
        $startTimer = microtime(true);

        $expression = new Expression;
        $postfix    = $expression->toPostfix("|".$phrase);
//        dump($postfix);
        foreach ($postfix as $token) {
            if ($token == '&') {
                $left  = array_pop($stack);
                $right = array_pop($stack);
                if (is_string($left)) {
                    $left = $this->getAllDocumentsForKeyword($this->stemmer->stem($left), true)
                        ->pluck('doc_id');
                }
                if (is_string($right)) {
                    $right = $this->getAllDocumentsForKeyword($this->stemmer->stem($right), true)
                        ->pluck('doc_id');
                }
                if (is_null($left)) {
                    $left = [];
                }

                if (is_null($right)) {
                    $right = [];
                }
                $stack[] = array_values(array_intersect($left, $right));
            } else
            if ($token == '|') {
                $left  = array_pop($stack);
                $right = array_pop($stack);

                if (is_string($left)) {
                    $left = $this->getAllDocumentsForKeyword($this->stemmer->stem($left), true)
                        ->pluck('doc_id');
                }
                if (is_string($right)) {
                    $right = $this->getAllDocumentsForKeyword($this->stemmer->stem($right), true)
                        ->pluck('doc_id');
                }
                if (is_null($left)) {
                    $left = [];
                }

                if (is_null($right)) {
                    $right = [];
                }
                $stack[] = array_unique(array_merge($left, $right));
            } else
            if ($token == '~') {
                $left = array_pop($stack);
                if (is_string($left)) {
                    $left = $this->getAllDocumentsForWhereKeywordNot($this->stemmer->stem($left), true)
                        ->pluck('doc_id');
                }
                if (is_null($left)) {
                    $left = [];
                }
                $stack[] = $left;
            } else {
                $stack[] = $token;
            }
        }
        if (count($stack)) {
            $docs = new Collection($stack[0]);
        } else {
            $docs = new Collection;
        }

        $counter = 0;
        $docs    = $docs->filter(function ($item) use (&$counter, $numOfResults) {
            $counter++;
            if ($counter <= $numOfResults) {
                return $item;
            }
            return false; // ?
        });

        $stopTimer = microtime(true);

        if ($this->isFileSystemIndex()) {
            return $this->filesystemMapIdsToPaths($docs)->toArray();
        }

        return [
            'ids'            => $docs->toArray(),
            'hits'           => $docs->count(),
            'execution_time' => round($stopTimer - $startTimer, 7) * 1000 ." ms"
        ];
    }

    /**
     * @param      $keyword
     * @param bool $noLimit
     * @param bool $isLastKeyword
     *
     * @return Collection
     */
    public function getAllDocumentsForKeyword($keyword, $noLimit = false, $isLastKeyword = false)
    {
        //dump($keyword,$noLimit,$isLastKeyword, $this->fuzziness);
        $word = $this->getWordlistByKeyword($keyword, $isLastKeyword);
        if (!isset($word[0])) {
            return new Collection([]);
        }
        if ($this->fuzziness) {
            return $this->getAllDocumentsForFuzzyKeyword($word, $noLimit);
        }

        return $this->getAllDocumentsForStrictKeyword($word, $noLimit);
    }

    /**
     * @param      $keyword
     * @param bool $noLimit
     *
     * @return Collection
     */
    public function getAllDocumentsForWhereKeywordNot($keyword, $noLimit = false)
    {
        $word = $this->getWordlistByKeyword($keyword);
        if (!isset($word[0])) {
            return new Collection([]);
        }
        $query = "SELECT * FROM doclist WHERE doc_id NOT IN (SELECT doc_id FROM doclist WHERE term_id = :id) GROUP BY doc_id ORDER BY hit_count DESC LIMIT {$this->maxDocs}";
        if ($noLimit) {
            $query = "SELECT * FROM doclist WHERE doc_id NOT IN (SELECT doc_id FROM doclist WHERE term_id = :id) GROUP BY doc_id ORDER BY hit_count DESC";
        }
        $stmtDoc = $this->index->prepare($query);

        $stmtDoc->bindValue(':id', $word[0]['id']);
        $stmtDoc->execute();
        return new Collection($stmtDoc->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param      $keyword
     * @param bool $isLastWord
     *
     * @return int
     */
    public function totalMatchingDocuments($keyword, $isLastWord = false)
    {
        $occurance = $this->getWordlistByKeyword($keyword, $isLastWord);
        if (isset($occurance[0])) {
            return $occurance[0]['num_docs'];
        }

        return 0;
    }

    /**
     * @param      $keyword
     * @param bool $isLastWord
     *
     * @return array
     */
    public function getWordlistByKeyword($keyword, $isLastWord = false)
    {
        $searchWordlist = "SELECT * FROM wordlist WHERE term like :keyword LIMIT 1";
       
        $stmtWord       = $this->index->prepare($searchWordlist);

        if ($this->asYouType && $isLastWord) {
            $searchWordlist = "SELECT * FROM wordlist WHERE term like :keyword ORDER BY length(term) ASC, num_hits DESC LIMIT 1";
            $stmtWord       = $this->index->prepare($searchWordlist);
            $stmtWord->bindValue(':keyword', mb_strtolower($keyword)."%");
        } else {
            $stmtWord->bindValue(':keyword', mb_strtolower($keyword));
        }
//        dump( $stmtWord->queryString, $keyword );
        $stmtWord->execute();
        $res = $stmtWord->fetchAll(PDO::FETCH_ASSOC);
       
        if ($this->fuzziness && !isset($res[0])) {
//            dump("return");
            return $this->fuzzySearch($keyword);
        }
         //dump($res);
        return $res;
    }

    /**
     * @param $keyword
     *
     * @return array
     */
    public function fuzzySearch($keyword)
    {
        
        $prefix         = substr($keyword, 0, $this->fuzzy_prefix_length);
        $searchWordlist = "SELECT * FROM wordlist WHERE term like :keyword ORDER BY num_hits DESC LIMIT {$this->fuzzy_max_expansions}";
        $stmtWord       = $this->index->prepare($searchWordlist);
        $stmtWord->bindValue(':keyword', mb_strtolower($prefix)."%");
        $stmtWord->execute();
        $matches = $stmtWord->fetchAll(PDO::FETCH_ASSOC);

        $resultSet = [];
        foreach ($matches as $match) {
            if (levenshtein($match['term'], $keyword) <= $this->fuzzy_distance) {
                $resultSet[] = $match;
            }
        }
        return $resultSet;
    }

    public function totalDocumentsInCollection()
    {
        return $this->getValueFromInfoTable('total_documents');
    }

    public function getStemmer()
    {
        return $this->stemmer;
    }

    public function setStemmer()
    {
        $stemmer = $this->getValueFromInfoTable('stemmer');
        if ($stemmer) {
            $this->stemmer = new $stemmer;
        } else {
            $this->stemmer = new PorterStemmer;
        }
    }

    /**
     * @return bool
     */
    public function isFileSystemIndex()
    {
        return $this->getValueFromInfoTable('driver') == 'filesystem';
    }

    public function getValueFromInfoTable($value)
    {
        $query = "SELECT * FROM info WHERE key = '$value'";
        $docs  = $this->index->query($query);

        return $docs->fetch(PDO::FETCH_ASSOC)['value'];
    }

    public function filesystemMapIdsToPaths($docs)
    {
        $query = "SELECT * FROM filemap WHERE id in (".$docs->implode(', ').");";
        $res   = $this->index->query($query)->fetchAll(PDO::FETCH_ASSOC);

        return $docs->map(function ($key) use ($res) {
            $index = array_search($key, array_column($res, 'id'));
            return $res[$index];
        });
    }

    public function info($str)
    {
        echo $str."\n";
    }

    public function breakIntoTokens($text)
    {
        return $this->tokenizer->tokenize($text);
    }

    /**
     * @param        $text
     * @param        $needle
     * @param string $tag
     * @param array  $options
     *
     * @return string
     */
    public function highlight($text, $needle, $tag = 'em', $options = [])
    {
        $hl = new Highlighter;
        return $hl->highlight($text, $needle, $tag, $options);
    }

    public function snippet($words, $fulltext, $rellength = 300, $prevcount = 50, $indicator = '...')
    {
        $hl = new Highlighter;
        return $hl->extractRelevant($words, $fulltext, $rellength, $prevcount, $indicator);
    }

    /**
     * @return TNTIndexer
     */
    public function getIndex()
    {
        $indexer           = new TNTIndexer;
        $indexer->inMemory = false;
        $indexer->setIndex($this->index);
        $indexer->setStemmer($this->stemmer);
        return $indexer;
    }

    /**
     * @param $words
     * @param $noLimit
     *
     * @return Collection
     */
    private function getAllDocumentsForFuzzyKeyword($words, $noLimit)
    {
        $binding_params = implode(',', array_fill(0, count($words), '?'));
        $query          = "SELECT * FROM doclist WHERE term_id in ($binding_params) ORDER BY hit_count DESC LIMIT {$this->maxDocs}";
        
        if ($noLimit) {
            $query = "SELECT * FROM doclist WHERE term_id in ($binding_params) ORDER BY hit_count DESC";
        }
        //dump($words,$query);
        $stmtDoc = $this->index->prepare($query);

        $ids = null;
        foreach ($words as $word) {
            $ids[] = $word['id'];
        }
        $stmtDoc->execute($ids);
        //dump($stmtDoc->queryString , $ids);
        return new Collection($stmtDoc->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param $word
     * @param $noLimit
     *
     * @return Collection
     */
    private function getAllDocumentsForStrictKeyword($word, $noLimit)
    {
        $query = "SELECT * FROM doclist WHERE term_id = :id ORDER BY hit_count DESC LIMIT {$this->maxDocs}";
        if ($noLimit) {
            $query = "SELECT * FROM doclist WHERE term_id = :id ORDER BY hit_count DESC";
        }
        $stmtDoc = $this->index->prepare($query);

        $stmtDoc->bindValue(':id', $word[0]['id']);
        $stmtDoc->execute();
        return new Collection($stmtDoc->fetchAll(PDO::FETCH_ASSOC));
    }
}
