<?php

if (isset($this)) { 
    
    $this->overwrite('include', function($file, $keepContent = false) {
        if ($keepContent === true) {
            require_once $file;
        } else {
            ob_start();
            require_once $file;
            $content = ob_get_clean();

            switch($keepContent) {
                case 'component':
                case 'components':
                    // keep components, remove the urls.

                    // @fixme - cheap way of deactivating the urls, we just rewrite the `url` attribute
                    // to `disabled-` and be done with it.
                    $content = preg_replace('~(<template\s)([^>]*)url~i', '$1$2 disabled-url', $content);
                break;
            }

            echo $content;
        }
    });
}