<?php

namespace Flux\Framework\Utils;

use Flux\Framework\Chain\Chain;
use Flux\Framework\Chain\DownloaderTrait;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;

class DataBrowser { 
    function __construct(
        private string|array $name, 
        private \Closure|\Traversable $datasource,
    ) { 
    }

    function render(?array $previewOptions = null): StreamedJsonResponse {
        $datasource = (new Chain($this->datasource))->cache('1h', [
            'id' => __METHOD__ . json_encode($this->name),
            'refreshCache' => (bool) ($previewOptions['refreshCache'] ?? false),
        ]);
        
        if ($previewOptions['search'] ?? false) { 
            $datasource->quicksearch($previewOptions['search']);
        }
        
        $previewOptions['size'] ??= 25;
        
        if ($previewOptions['size'] !== 'all') { 
            $datasource->head(max(1, $previewOptions['size']));
        }
        
        $previewOptions['mode'] ??= null;
        
        if (is_array($this->name)) { 
            $filename = join(' ',array_filter($this->name, 'is_scalar'));
        } else {
            $filename = $this->name;
        }

        if ($previewOptions['mode']) { 
            $datasource = Chain::withFeatures(
                DownloaderTrait::class
            )($datasource)
                ->outputDownload($previewOptions['mode'], ['filename' => $filename]);
        }
        
        return new StreamedJsonResponse([
            'data' => $datasource->filter()->toArray(),    
            'total_records' => $datasource->getCacheLineCount(),
        ]);
    }
}