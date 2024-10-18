<?php

namespace Flux\Framework\UI;

class HtmlUtils {
    static function append($tag, $inject, $content, $limit = 1) {
        $content = preg_replace_callback('~</'.$tag . '>~', function() use ($tag, $inject) {
            return $inject . "\n</". $tag . ">";
        }, $content, $limit, $count);

        if ($count == 0) {
            $content .= "\n$inject\n";
        }
        return $content;
    }
    static function removeLine(string $match, string $content): string {
        return preg_replace("~\n.*?$match.*?\n~", "", $content);
    }

    static function prepend($tag, $inject, $content, $limit = 1) {
        $content = preg_replace_callback('~<'.$tag . '[^>]*>~', function($match) use ($tag, $inject) {
            return $match[0] . "\n" . $inject . "\n";
        }, $content, $limit, $count);

        if ($count == 0) {
            $content = "$inject\n".$content;
        }
        return $content;
    }

    static function addStylesheet($href, $content) { 
        $tag = '<link rel="stylesheet" href="'.$href.'">';
        return static::append('head', $tag, $content);
    }
    static function addScript($src, $content) {
        $tag = '<script src="'.$src.'"></script>';
        return static::append('body', $tag, $content);
    }

    static function autoCompleteHtmlDocument($content) {
        if (stripos($content, '<body') === false) { 
            $content = "<body>\n" . $content . "\n</body>";
        }

        if (stripos($content, '<head>') === false) {
            $content = "<head>\n</head>\n" . $content;
        }

        if (stripos($content, '<html') === false) {
            $content = "<html>\n" . $content . "\n</html>";
        }

        if (stripos($content, '<!DOCTYPE') === false) {
            $content = "<!DOCTYPE html>\n" . $content;
        }

        if (stripos($content, '<meta charset') === false) {
            $content = static::append('head', '<meta charset="utf-8">', $content);
        }
        return $content;
    }


}