<?php

namespace PieCrust\Page;

use \Exception;
use \FilesystemIterator;
use PieCrust\IPage;
use PieCrust\IPieCrust;
use PieCrust\PieCrustException;
use PieCrust\Data\PaginationData;
use PieCrust\Util\PathHelper;
use PieCrust\Util\PieCrustHelper;
use PieCrust\Util\UriBuilder;


/**
 * A class that exposes the list of pages in a folder to another page.
 *
 * @formatObject
 * @explicitInclude
 * @documentation The list of pages in the same directory as the current one. See the documentation for more information.
 */
class Linker implements \ArrayAccess, \Iterator, \Countable
{
    protected $page;
    protected $baseDir;

    protected $sortByName;
    protected $sortByReverse;

    protected $linksCache;
    
    /**
     * Creates a new instance of Linker.
     */
    public function __construct(IPage $page, $dir = null)
    {
        $this->page = $page;
        $this->baseDir = ($dir != null) ? $dir : dirname($page->getPath());
        $this->baseDir = rtrim($this->baseDir, '/\\') . '/';

        $this->sortByName = null;
        $this->sortByReverse = false;
    }

    // {{{ Template Data Members
    /**
     * Gets the name of the current directory.
     */
    public function name()
    {
        if (strlen($this->baseDir) == strlen($this->page->getApp()->getPagesDir()))
                return '';
        return basename($this->baseDir);
    }
    
    /**
     * Gets whether this maps to a directory. Always returns true.
     */
    public function is_dir()
    {
        return true;
    }

    /**
     * Gets whether this maps to the current page. Always returns false.
     */
    public function is_self()
    {
        return false;
    }

    /**
     * @noCall
     */
    public function sortBy($name, $reverse = false)
    {
        $this->sortByName = $name;
        $this->sortByReverse = $reverse;
        return $this;
    }
    // }}}
    
    // {{{ Countable members
    public function count()
    {
        $this->ensureLinksCache();
        return count($this->linksCache);
    }
    // }}}

    // {{{ ArrayAccess members
    public function offsetExists($offset)
    {
        $this->ensureLinksCache();
        return isset($this->linksCache[$offset]);
    }
    
    public function offsetGet($offset) 
    {
        $this->ensureLinksCache();
        return $this->linksCache[$offset];
    }
    
    public function offsetSet($offset, $value)
    {
        throw new PieCrustException('Linker is read-only.');
    }
    
    public function offsetUnset($offset)
    {
        throw new PieCrustException('Linker is read-only.');
    }
    // }}}
    
    // {{{ Iterator members
    public function rewind()
    {
        $this->ensureLinksCache();
        reset($this->linksCache);
    }
  
    public function current()
    {
        $this->ensureLinksCache();
        return current($this->linksCache);
    }
  
    public function key()
    {
        $this->ensureLinksCache();
        return key($this->linksCache);
    }
  
    public function next()
    {
        $this->ensureLinksCache();
        next($this->linksCache);
    }
  
    public function valid()
    {
        $this->ensureLinksCache();
        return key($this->linksCache) !== null;
    }
    // }}}
    
    protected function ensureLinksCache()
    {
        if ($this->linksCache === null)
        {
            try
            {
                $pieCrust = $this->page->getApp();
                $pageRepository = $pieCrust->getEnvironment()->getPageRepository();

                $this->linksCache = array();
                $skipNames = array('Thumbs.db');
                $it = new FilesystemIterator($this->baseDir);
                foreach ($it as $item)
                {
                    $filename = $item->getFilename();

                    // Skip dot files, Thumbs.db, etc.
                    if (!$filename or $filename[0] == '.')
                        continue;
                    if (in_array($filename, $skipNames))
                        continue;
                    
                    if ($item->isDir())
                    {
                        $linker = new Linker($this->page, $item->getPathname());
                        $this->linksCache[$filename . '_'] = $linker;
                        // We add '_' at the end of the directory name to avoid
                        // collisions with a possibly existing page with the same
                        // name (since we strip out the '.html' extension).
                        // This means the user must access directories with
                        // 'link.dirname_' instead of 'link.dirname' but hey, if
                        // you have a better idea, send me an email!
                    }
                    else
                    {
                        $path = $item->getPathname();
                        try
                        {
                            // To get the link's page, we need to be careful with the case
                            // where that page is the currently rendering one. This is
                            // because it could be rendering a sub-page -- but we would be
                            // requesting the default first page, which would effectively
                            // change the page number *while* we're rendering, which leads
                            // to all kinds of bad things!
                            // TODO: obviously, there needs to be some design changes to
                            // prevent this kind of chaotic behaviour. 
                            if ($path == $this->page->getPath())
                            {
                                $page = $this->page;
                            }
                            else
                            {
                                $relativePath = PathHelper::getRelativePath($pieCrust->getPagesDir(), $path);
                                $uri = UriBuilder::buildUri($relativePath);
                                $page = $pageRepository->getOrCreatePage($uri, $path);
                            }
                            
                            $key = preg_replace('/\.[a-zA-Z0-9]+$/', '', $filename);
                            $key = str_replace('.', '_', $key);
                            $this->linksCache[$key] = array(
                                'uri' => PieCrustHelper::formatUri($pieCrust, $page->getUri()),
                                'name' => $key,
                                'is_dir' => false,
                                'is_self' => ($page == $this->page),
                                'page' => new PaginationData($page)
                            );
                        }
                        catch (Exception $e)
                        {
                            throw new PieCrustException(
                                "Error while loading page '{$path}' for linking from '{$this->page->getUri()}': " .
                                $e->getMessage(), 0, $e
                            );
                        }
                    }
                }

                if ($this->sortByName)
                {
                    if (false === usort($this->linksCache, array($this, 'sortByCustom')))
                        throw new PieCrustException("Error while sorting pages with the specified setting: {$this->sortByName}");
                }
            }
            catch (Exception $e)
            {
                throw new PieCrustException(
                    "Error while building the links from page '{$this->page->getUri()}': " .
                    $e->getMessage(), 0, $e
                );
            }
        }
    }

    protected function sortByCustom($link1, $link2)
    {
        $link1IsLinker = ($link1 instanceof Linker);
        $link2IsLinker = ($link2 instanceof Linker);

        if ($link1IsLinker && $link2IsLinker)
        {
            $c = strcmp($link1->name(), $link2->name());
            return $this->sortByReverse ? -$c : $c;
        }
        if ($link1IsLinker)
            return $this->sortByReverse ? 1 : -1;
        if ($link2IsLinker)
            return $this->sortByReverse ? -1 : 1;

        $page1 = $link1['page']->getPage();
        $value1 = $page1->getConfig()->getValue($this->sortByName);
        $page2 = $link2['page']->getPage();
        $value2 = $page2->getConfig()->getValue($this->sortByName);
        
        if ($value1 == null && $value2 == null)
            return 0;
        if ($value1 == null && $value2 != null)
            return $this->sortByReverse ? 1 : -1;
        if ($value1 != null && $value2 == null)
            return $this->sortByReverse ? -1 : 1;
        if ($value1 == $value2)
            return 0;
        if ($this->sortByReverse)
            return ($value1 < $value2) ? 1 : -1;
        else
            return ($value1 < $value2) ? -1 : 1;
    }

}
