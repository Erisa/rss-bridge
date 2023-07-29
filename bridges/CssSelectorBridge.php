<?php

class CssSelectorBridge extends BridgeAbstract
{
    const MAINTAINER = 'ORelio';
    const NAME = 'CSS Selector Bridge';
    const URI = 'https://github.com/RSS-Bridge/rss-bridge/';
    const DESCRIPTION = 'Convert any site to RSS feed using CSS selectors (Advanced Users)';
    const PARAMETERS = [
        [
            'home_page' => [
                'name' => 'Site URL: Home page with latest articles',
                'exampleValue' => 'https://example.com/blog/',
                'required' => true
            ],
            'url_selector' => [
                'name' => 'Selector for article links or their parent elements',
                'title' => <<<EOT
                    This bridge works using CSS selectors, e.g. "a.article" will match all <a class="article" 
                    href="URL">TITLE</a> on home page, each one being treated as a feed item. &#10;&#13;
                    Instead of just a link you can selet one of its parent element. Everything inside that
                    element becomes feed item content, e.g. image and summary present on home page.
                    When doing so, the first link inside the selected element becomes feed item URL/Title.
                    EOT,
                'exampleValue' => 'a.article',
                'required' => true
            ],
            'url_pattern' => [
                'name' => '[Optional] Pattern for site URLs to keep in feed',
                'title' => 'Optionally filter items by applying a regular expression on their URL',
                'exampleValue' => '/blog/article/.*',
            ],
            'content_selector' => [
                'name' => '[Optional] Selector to expand each article content',
                'title' => <<<EOT
                    When specified, the bridge will fetch each article from its URL
                    and extract content using the provided selector (Slower!)
                    EOT,
                'exampleValue' => 'article.content',
            ],
            'content_cleanup' => [
                'name' => '[Optional] Content cleanup: List of items to remove',
                'title' => 'Selector for unnecessary elements to remove inside article contents.',
                'exampleValue' => 'div.ads, div.comments',
            ],
            'title_cleanup' => [
                'name' => '[Optional] Text to remove from expanded article title',
                'title' => <<<EOT
                    When fetching each article page, feed item title comes from page title. 
                    Specify here some text from page title that need to be removed, e.g. " | BlogName".
                    EOT,
                'exampleValue' => ' | BlogName',
            ],
            'limit' => self::LIMIT
        ]
    ];

    private $feedName = '';

    public function getURI()
    {
        $url = $this->getInput('home_page');
        if (empty($url)) {
            $url = parent::getURI();
        }
        return $url;
    }

    public function getName()
    {
        if (!empty($this->feedName)) {
            return $this->feedName;
        }
        return parent::getName();
    }

    public function collectData()
    {
        $url = $this->getInput('home_page');
        $url_selector = $this->getInput('url_selector');
        $url_pattern = $this->getInput('url_pattern');
        $content_selector = $this->getInput('content_selector');
        $content_cleanup = $this->getInput('content_cleanup');
        $title_cleanup = $this->getInput('title_cleanup');
        $limit = $this->getInput('limit') ?? 10;

        $html = defaultLinkTo(getSimpleHTMLDOM($url), $url);
        $this->feedName = $this->getPageTitle($html, $title_cleanup);
        $items = $this->htmlFindEntries($html, $url_selector, $url_pattern, $limit, $content_cleanup);

        if (empty($content_selector)) {
            $this->items = $items;
        } else {
            foreach ($items as $item) {
                $this->items[] = $this->expandEntryWithSelector(
                    $item['uri'],
                    $content_selector,
                    $content_cleanup,
                    $title_cleanup,
                    $item['title']
                );
            }
        }
    }

    /**
     * Filter a list of URLs using a pattern and limit
     * @param array $links List of URLs
     * @param string $url_pattern Pattern to look for in URLs
     * @param int $limit Optional maximum amount of URLs to return
     * @return array Array of URLs
     */
    protected function filterUrlList($links, $url_pattern, $limit = 0)
    {
        if (!empty($url_pattern)) {
            $url_pattern = '/' . str_replace('/', '\/', $url_pattern) . '/';
            $links = array_filter($links, function ($url) {
                return preg_match($url_pattern, $url) === 1;
            });
        }

        if ($limit > 0 && count($links) > $limit) {
            $links = array_slice($links, 0, $limit);
        }

        return $links;
    }

    /**
     * Retrieve title from webpage URL or DOM
     * @param string|object $page URL or DOM to retrieve title from
     * @param string $title_cleanup optional string to remove from webpage title, e.g. " | BlogName"
     * @return string Webpage title
     */
    protected function getPageTitle($page, $title_cleanup = null)
    {
        if (is_string($page)) {
            $page = getSimpleHTMLDOMCached($page);
        }
        $title = html_entity_decode($page->find('title', 0)->plaintext);
        if (!empty($title)) {
            $title = trim(str_replace($title_cleanup, '', $title));
        }
        return $title;
    }

    /**
     * Remove all elements from HTML content matching cleanup selector
     * @param string|object $content HTML content as HTML object or string
     * @return string|object Cleaned content (same type as input)
     */
    protected function cleanArticleContent($content, $cleanup_selector)
    {
        $string_convert = false;
        if (is_string($content)) {
            $string_convert = true;
            $content = str_get_html($content);
        }

        if (!empty($cleanup_selector)) {
            foreach ($content->find($cleanup_selector) as $item_to_clean) {
                $item_to_clean->outertext = '';
            }
        }

        if ($string_convert) {
            $content = $content->outertext;
        }
        return $content;
    }

    /**
     * Retrieve first N link+title+truncated-content from webpage URL or DOM satisfying the specified criteria
     * @param string|object $page URL or DOM to retrieve feed items from
     * @param string $url_selector DOM selector for matching links or their parent element
     * @param string $url_pattern Optional filter to keep only links matching the pattern
     * @param int $limit Optional maximum amount of URLs to return
     * @param string $content_cleanup Optional selector for removing elements, e.g. "div.ads, div.comments"
     * @return array of items {'uri': entry_url, 'title': entry_title, ['content': when present in DOM] }
     */
    protected function htmlFindEntries($page, $url_selector, $url_pattern = '', $limit = 0, $content_cleanup = null)
    {
        if (is_string($page)) {
            $page = getSimpleHTMLDOM($page);
        }

        $links = $page->find($url_selector);

        if (empty($links)) {
            returnClientError('No results for URL selector');
        }

        $link_to_item = [];
        foreach ($links as $link) {
            $item = [];
            if ($link->innertext != $link->plaintext) {
                $item['content'] = $link->innertext;
            }
            if ($link->tag != 'a') {
                $link = $link->find('a', 0);
            }
            $item['uri'] = $link->href;
            $item['title'] = $link->plaintext;
            if (isset($item['content'])) {
                $item['content'] = convertLazyLoading($item['content']);
                $item['content'] = defaultLinkTo($item['content'], $item['uri']);
                $item['content'] = $this->cleanArticleContent($item['content'], $content_cleanup);
            }
            $link_to_item[$link->href] = $item;
        }

        $links = $this->filterUrlList(array_keys($link_to_item), $url_pattern, $limit);

        if (empty($links)) {
            returnClientError('No results for URL pattern');
        }

        $items = [];
        foreach ($links as $link) {
            $items[] = $link_to_item[$link];
        }

        return $items;
    }

    /**
     * Retrieve article content from its URL using content selector and return a feed item
     * @param string $entry_url URL to retrieve article from
     * @param string $content_selector HTML selector for extracting content, e.g. "article.content"
     * @param string $content_cleanup Optional selector for removing elements, e.g. "div.ads, div.comments"
     * @param string $title_cleanup Optional string to remove from article title, e.g. " | BlogName"
     * @param string $title_default Optional title to use when could not extract title reliably
     * @return array Entry data: uri, title, content
     */
    protected function expandEntryWithSelector($entry_url, $content_selector, $content_cleanup = null, $title_cleanup = null, $title_default = null)
    {
        if (empty($content_selector)) {
            returnClientError('Please specify a content selector');
        }

        $entry_html = getSimpleHTMLDOMCached($entry_url);
        $article_content = $entry_html->find($content_selector);

        if (!empty($article_content)) {
            $article_content = $article_content[0];
        } else {
            returnClientError('Could not find content selector at URL: ' . $entry_url);
        }

        $article_content = convertLazyLoading($article_content);
        $article_content = defaultLinkTo($article_content, $entry_url);
        $article_content = $this->cleanArticleContent($article_content, $content_cleanup);

        $article_title = $this->getPageTitle($entry_html, $title_cleanup);
        if (!empty($title_default) && (empty($article_title) || $article_title === $this->feedName)) {
            $article_title = $title_default;
        }

        $item = [];
        $item['uri'] = $entry_url;
        $item['title'] = $article_title;
        $item['content'] = $article_content;
        return $item;
    }
}
