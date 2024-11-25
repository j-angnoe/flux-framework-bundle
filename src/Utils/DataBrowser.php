<?php

namespace Flux\Framework\Utils;

use Flux\Framework\Chain\Chain;
use Flux\Framework\Chain\DownloaderTrait;
use JsonSerializable;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;

class DataBrowser implements JsonSerializable { 
    function __construct(
        private string|array $name, 
        private \Closure|\Traversable $datasource,
        private bool $cache = true,
        private ?array $previewOptions = null
    ) { 
    }


    function getData(?array $previewOptions = null): Chain {
        $datasource = (new Chain($this->datasource));
        
        $previewOptions['cache'] ??= $this->cache;

        if ($previewOptions['cache']) { 
            $datasource->cache('1h', [
                'id' => __METHOD__ . json_encode(array_merge([$this->name], $previewOptions['query'] ?? [])),
                'refreshCache' => (bool) ($previewOptions['refreshCache'] ?? false),
                'reverse' => (bool) ($previewOptions['reverse'] ?? false),
            ]);
        }
        
        if ($previewOptions['search'] ?? false) { 
            $datasource->quicksearch($previewOptions['search']);
            if ($previewOptions['size'] !== 'all' || $previewOptions['size'] < 250) {
                $previewOptions['size'] = 250;
            }
            $previewOptions['skip'] = 0;
        }
        
        if ($previewOptions['sorts'] ?? false) {
            foreach ($previewOptions['sorts'] as $key=>$value) { 
                if ($value === null) continue;
                $direction = $value === -1 ? 'asc' : 'desc';

                // Ensure the requested column always exists
                // this helps with when the column is sparse.
                $datasource->map(function($row) use ($key) {
                    $row[$key] ??= null;
                    return $row;
                });

                $datasource->sort(function($row) use ($key) {
                    $value = $row[$key]??null;
                    if (is_array($value) && array_is_list($value)) { 
                        return count($value);
                    }
                    return $value;
                }, $direction);
            }
        }
        $previewOptions['size'] ??= 25;
        
        if ($previewOptions['size'] !== 'all') { 
            $previewOptions['skip'] ??= 0;

            if ($previewOptions['skip'] < 0) {
                $datasource->skip(abs($previewOptions['skip']));
                $datasource->tail($previewOptions['size'] + abs($previewOptions['skip'] - $previewOptions['size']) + 1);
                $datasource->head(max(1,$previewOptions['size']));

            } else { 
                $datasource->skip($previewOptions['skip']);
                $datasource->head(max(1, $previewOptions['size']));
            }
        } 
        
        return $datasource->filter();        
    }

    function setPreviewOptions(?array $previewOptions = null) {
        $this->previewOptions = $previewOptions;
    }

    function jsonSerialize() {
        return $this->render($this->previewOptions);
    }

    function render(?array $previewOptions = null): StreamedJsonResponse {
        $previewOptions['mode'] ??= null;
        
        $datasource = $this->getData($previewOptions);

        if ($previewOptions['mode']) { 
            if (is_array($this->name)) { 
                $filename = join(' ',array_filter($this->name, 'is_scalar'));
            } else {
                $filename = $this->name;
            }
            $datasource = Chain::withFeatures(
                DownloaderTrait::class
            )($datasource)
                ->outputDownload($previewOptions['mode'], ['filename' => $filename]);
        }

        $data = $datasource->filter()->toArray();

        try { 
            $total_records = $datasource->getCacheLineCount();
        } catch(\Throwable) { 
            $total_records = count($data);
        }

        if ($previewOptions['search'] ?? false) { 
            $total_records = count($data);
        }
        return new StreamedJsonResponse([
            'name' => join(' ', array_filter(toa($this->name), 'is_scalar')),
            'data' => $data,
            'total_records' => $total_records,
        ]);
    }
}